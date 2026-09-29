<?php

namespace App\Storage;

use App\Providers\Contracts\EmbeddingClient;
use App\Skills\Frontmatter;
use App\Skills\SkillLibrary;
use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;

/**
 * Reads skills and prompts from disk and hands them to a {@see LibraryIndex}.
 *
 * The trust decision lives HERE, at the point of reading, and nowhere else. LibraryIndex writes
 * whatever it is given; this class decides what is allowed to be read. One place to audit, and a
 * future caller cannot accidentally import a project directory by going through the writer
 * instead of the reader.
 *
 * A skill is instructions injected into a prompt, so a repository you clone must never be able to
 * grant itself standing instructions just because you ran Paider inside it. The filesystem rule
 * SkillLibrary already enforces (home only, project-local refused unconditionally) is applied
 * again at import time, because a new import path is a new way for that boundary to be crossed.
 */
final class LibraryImporter
{
    public const KIND_SKILL = 'skill';

    public const KIND_PROMPT = 'prompt';

    /**
     * Import from an explicit path, refusing anything inside the project.
     *
     * @return array{imported: int, skipped: int, refused: ?string}
     */
    public static function fromPath(string $directory, LibraryIndex $index, ?EmbeddingClient $embedder = null): array
    {
        $real = realpath($directory);

        if ($real === false || ! is_dir($real)) {
            throw new RuntimeException("Not a directory: {$directory}");
        }

        if (LibraryIndex::refusesPath($real)) {
            // Refused BEFORE any read. A refusal that happened after parsing would already have
            // executed whatever the directory's frontmatter caused us to load.
            return ['imported' => 0, 'skipped' => 0, 'refused' => $real];
        }

        $items = self::collect($real);

        $result = $index->importItems($items, $embedder);

        return $result + ['refused' => null];
    }

    /**
     * Import the user's OWN home skill library — the one source SkillLibrary already trusts.
     *
     * Goes through SkillLibrary's own discovery rather than a second directory walk, so there is
     * exactly one definition of what a skill is and one definition of where home skills live.
     *
     * @return array{imported: int, skipped: int, refused: ?string}
     */
    public static function fromHomeSkills(LibraryIndex $index, ?EmbeddingClient $embedder = null): array
    {
        $items = [];

        foreach (SkillLibrary::index() as $entry) {
            $loaded = SkillLibrary::load($entry['name']);

            if (! $loaded['ok'] || ! is_string($loaded['body'] ?? null)) {
                continue;
            }

            $items[] = [
                'kind' => self::KIND_SKILL,
                'name' => (string) $loaded['name'],
                'description' => $loaded['description'] ?? $entry['description'],
                'body' => $loaded['body'],
                'source' => 'home',
            ];
        }

        $result = $index->importItems($items, $embedder);

        return $result + ['refused' => null];
    }

    /**
     * Import prompts from an already-vetted path.
     *
     * A prompt file is a bare `name` + body, or YAML-ish `name:` / `description:` headers. Parsed
     * leniently on purpose: a prompt is prose, not a protocol, and a prompt that fails to parse
     * should be skipped like a malformed skill rather than taking the batch down.
     *
     * @return array{imported: int, skipped: int, refused: ?string}
     */
    public static function importPrompts(string $directory, LibraryIndex $index, ?EmbeddingClient $embedder = null): array
    {
        $real = realpath($directory);

        if ($real === false || ! is_dir($real)) {
            throw new RuntimeException("Not a directory: {$directory}");
        }

        if (LibraryIndex::refusesPath($real)) {
            return ['imported' => 0, 'skipped' => 0, 'refused' => $real];
        }

        $items = [];

        foreach (new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($real, FilesystemIterator::SKIP_DOTS)
        ) as $file) {
            if (! $file->isFile() || ! in_array($file->getExtension(), ['md', 'txt', 'prompt'], true)) {
                continue;
            }

            $raw = @file_get_contents($file->getPathname());

            if (! is_string($raw) || trim($raw) === '') {
                continue;
            }

            $parsed = self::parsePrompt($raw, $file->getBasename('.'.pathinfo($file->getPathname(), PATHINFO_EXTENSION)));

            if ($parsed === null) {
                continue;
            }

            $parsed['kind'] = self::KIND_PROMPT;
            $parsed['source'] = $real;
            $items[] = $parsed;
        }

        return $index->importItems($items, $embedder) + ['refused' => null];
    }

    /**
     * @return array{name: string, description: string, body: string}|null
     */
    private static function parsePrompt(string $contents, string $fallbackName): ?array
    {
        $name = $fallbackName;
        $description = '';
        $lines = preg_split('/\r\n|\r|\n/', $contents) ?: [];
        $bodyStart = 0;

        // Leading "key: value" headers, stopping at the first blank line or non-header line.
        foreach ($lines as $index => $line) {
            if (trim($line) === '') {
                $bodyStart = $index + 1;
                break;
            }

            if (! preg_match('/^([A-Za-z_][A-Za-z0-9_-]*)\s*:\s*(.*)$/', $line, $m)) {
                $bodyStart = $index;
                break;
            }

            $key = strtolower($m[1]);

            if ($key === 'name') {
                $name = trim($m[2]);
            } elseif ($key === 'description') {
                $description = trim($m[2]);
            }
        }

        $body = trim(implode("\n", array_slice($lines, $bodyStart)));

        // No usable body: a prompt with no text cannot be injected, and storing it would only
        // make search return an empty hit.
        if ($body === '' || $name === '') {
            return null;
        }

        return ['name' => $name, 'description' => $description, 'body' => $body];
    }

    /**
     * Read skill-shaped directories (SKILL.md) out of an already-vetted absolute path.
     *
     * @return array<int, array{kind: string, name: string, description: string, body: string, source: string}>
     */
    public static function collect(string $directory): array
    {
        $items = [];
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS)
        );

        foreach ($files as $file) {
            if (! $file->isFile() || $file->getFilename() !== 'SKILL.md') {
                continue;
            }

            $raw = @file_get_contents($file->getPathname());

            if (! is_string($raw) || trim($raw) === '') {
                continue;
            }

            $parsed = Frontmatter::parse($raw);

            if ($parsed['error'] !== null) {
                // One unparseable skill must not take the rest of the corpus down.
                continue;
            }

            $name = $parsed['name'] ?? basename(dirname($file->getPathname()));

            if (! is_string($name) || $name === '') {
                continue;
            }

            $items[] = [
                'kind' => self::KIND_SKILL,
                'name' => $name,
                'description' => is_string($parsed['description'] ?? null) ? $parsed['description'] : '',
                'body' => self::bodyAfterFrontmatter($raw),
                'source' => $directory,
            ];
        }

        return $items;
    }

    /**
     * Everything after the closing '---' of the frontmatter block.
     *
     * Duplicated from SkillLibrary (where it is private) rather than made public, because the two
     * answer different questions: SkillLibrary's version is trusted because parseFile already
     * proved the frontmatter is valid, and widening its visibility would hand a body-splitter to
     * callers that have not proved anything. Both are three lines and both are pinned by tests
     * on their own side.
     */
    private static function bodyAfterFrontmatter(string $contents): string
    {
        $lines = preg_split('/\r\n|\r|\n/', $contents) ?: [];
        $closing = null;

        foreach ($lines as $index => $line) {
            if ($index > 0 && rtrim($line) === '---') {
                $closing = $index;
                break;
            }
        }

        if ($closing === null) {
            return trim($contents);
        }

        return trim(implode("\n", array_slice($lines, $closing + 1)));
    }
}
