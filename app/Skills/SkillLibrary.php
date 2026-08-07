<?php

namespace App\Skills;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Discovers skills, enforces the one trust boundary that makes discovery safe, and builds the
 * name+description index that rides in the prompt.
 *
 * This class is the direct continuation of the clone-to-RCE fix in ProjectEnv::fromEnvironment():
 * a skill is instructions loaded from disk and injected into the prompt, so a directory a cloned
 * REPOSITORY controls is exactly as dangerous as a repository-controlled `.paider/.env` — it is
 * a way for a hostile repo to grant itself standing instructions the moment someone clones it and
 * runs Paider inside. So discovery reads from exactly one place: `~/.paider/skills`, a directory
 * the PROJECT cannot write to. Anything under the project itself is refused, unconditionally —
 * see refusedProjectSkillsNotice() for why there is deliberately no escape hatch.
 *
 * No caching, on purpose, same posture as MemoryStore::all(): every call re-reads the filesystem,
 * which is the actual source of truth. The corpus this was measured against (225 dirs) makes
 * that cheap, and a stale in-memory list of a directory the user edits by hand (adding/removing
 * skills, symlinking new ones in) is a worse bug than a few extra file reads.
 */
final class SkillLibrary
{
    /** The ONLY place skills are discovered from — relative to $HOME. */
    private const HOME_RELATIVE = '.paider/skills';

    /** Project-local skill dirs that are trust-boundary REFUSED, never loaded. */
    private const REFUSED_PROJECT_RELATIVE = ['.paider/skills', '.claude/skills'];

    /**
     * Cap on how many skills the index carries. Loop rebuilds the system prompt on every
     * iteration of a turn (up to 10 tool calls), and the index rides in that prompt — an
     * unbounded corpus is therefore a PER-ITERATION token cost, not a one-time one. 300 covers
     * the measured real corpus (225 dirs) with headroom; anything past the cap is silently
     * dropped rather than erroring, same trim-not-crash posture as MemoryStore::DEFAULT_LIMIT.
     */
    public const MAX_INDEXED = 300;

    /**
     * The index carries name + description ONLY, and BOTH fields are capped: a full index of
     * 216 real skills already runs ~25k tokens against a system prompt that is otherwise well
     * under 1k, so an uncapped field would let one verbose skill tax every request
     * disproportionately. Bodies never ride in the index at all — load() fetches one
     * explicitly, only when the model asks for it by name.
     */
    public const DESCRIPTION_TRUNCATE = 200;

    /**
     * Real skill names are short slugs — this is a defensive ceiling, not a working limit, same
     * reasoning as DESCRIPTION_TRUNCATE applied to the field that doubles as load()'s lookup
     * key. Kept well above any real name so truncation here is only ever a sign of abuse.
     */
    public const NAME_TRUNCATE = 100;

    /** Same reasoning, applied to a single explicitly-loaded body: bound the worst case. */
    public const MAX_BODY_BYTES = 32_000;

    /**
     * The four capability words the corpus census counted when it measured 18% of skills as
     * "structurally Claude-dependent" (>=3 references). Paider has none of them: no Task/
     * subagent tool, no MCP client, no browser, no artifact renderer.
     */
    private const UNAVAILABLE_PATTERNS = [
        'subagents' => '/\b(sub-?agents?|Task tool)\b/i',
        'MCP' => '/\bMCP\b|\bmcp__/i',
        'browser' => '/\bbrowser\b/i',
        'artifacts' => '/\bartifacts?\b/i',
    ];

    /**
     * name + truncated description for every skill under ~/.paider/skills, capped at
     * MAX_INDEXED. Unparseable SKILL.md files are skipped, not failed — one bad skill must not
     * take the rest of the corpus down with it.
     *
     * @return array<int, array{name: string, description: string}>
     */
    public static function index(): array
    {
        $entries = [];
        $seenNames = [];

        foreach (self::homeSkillFiles() as $path) {
            $parsed = self::parseFile($path);

            if ($parsed === null) {
                continue;
            }

            // load() resolves a name to the FIRST matching file in the same glob order — an
            // index entry for a shadowed duplicate would advertise a skill the model can never
            // actually reach, so duplicates past the first are dropped here rather than shown.
            if (isset($seenNames[$parsed['name']])) {
                continue;
            }

            $seenNames[$parsed['name']] = true;

            $entries[] = [
                'name' => self::truncateField($parsed['name'], self::NAME_TRUNCATE),
                'description' => self::truncateField($parsed['description'], self::DESCRIPTION_TRUNCATE),
            ];

            if (count($entries) >= self::MAX_INDEXED) {
                break;
            }
        }

        return $entries;
    }

    /**
     * The full body of one skill, by the name its own frontmatter declares — the index never
     * carries this, so callers ask for it explicitly once the model has decided a skill applies.
     *
     * unavailableTools is surfaced HERE, at load time, rather than hidden: a skill body that
     * tells the agent to dispatch a subagent, call an MCP tool, drive a browser, or render an
     * artifact is instructing it to use something Paider does not have, and the caller (whatever
     * wraps this in a Tool) needs that fact in hand to warn the model rather than let it try and
     * silently fail partway through.
     *
     * @return array{ok: bool, name: ?string, description: ?string, body: ?string,
     *               unavailableTools: array<int, string>, truncated: bool, error: ?string}
     */
    public static function load(string $name): array
    {
        foreach (self::homeSkillFiles() as $path) {
            $contents = @file_get_contents($path);

            if ($contents === false) {
                continue;
            }

            $parsed = Frontmatter::parse($contents);

            if ($parsed['error'] !== null || $parsed['name'] !== $name) {
                continue;
            }

            $body = self::bodyAfterFrontmatter($contents);
            $truncated = strlen($body) > self::MAX_BODY_BYTES;

            if ($truncated) {
                // mb_strcut, not substr: a byte-wise cut can land inside a multi-byte
                // character, producing invalid UTF-8 that later throws when the provider
                // client JSON-encodes the request — killing the whole turn, not just this
                // tool call. mb_strcut is byte-bounded (still respects MAX_BODY_BYTES) but
                // never splits a character.
                $body = mb_strcut($body, 0, self::MAX_BODY_BYTES);
            }

            return [
                'ok' => true,
                'name' => $parsed['name'],
                'description' => $parsed['description'],
                'body' => $body,
                'unavailableTools' => self::unavailableTools($body),
                'truncated' => $truncated,
                'error' => null,
            ];
        }

        return [
            'ok' => false,
            'name' => null,
            'description' => null,
            'body' => null,
            'unavailableTools' => [],
            'truncated' => false,
            'error' => "no skill named '{$name}' under ~/".self::HOME_RELATIVE,
        ];
    }

    /**
     * The trust-boundary message, or null when there is nothing to report. Deliberately
     * unconditional: there is no env knob that can suppress or reroute it, because a
     * project-readable knob would just be the `.paider/.env` PAIDER_YOLO hole one layer down —
     * a repository that could say "load my skills anyway" would have granted itself standing
     * instructions on every future prompt the moment someone cloned it. Silence here would be
     * worse than the refusal: the user would believe their project skills loaded when they did
     * not. Returned rather than printed directly — this class has no terminal to print to;
     * whatever wires it into the chat loop decides when to surface it.
     */
    public static function refusedProjectSkillsNotice(): ?string
    {
        $root = getcwd() ?: '.';
        $found = [];

        foreach (self::REFUSED_PROJECT_RELATIVE as $relative) {
            $dir = $root.'/'.$relative;

            if (is_dir($dir)) {
                $found[$relative] = self::countSkillFiles($dir);
            }
        }

        if ($found === []) {
            return null;
        }

        return sprintf(
            'Skipped %d project skill(s) under %s — skills load from ~/%s only.',
            array_sum($found),
            implode(' and ', array_keys($found)),
            self::HOME_RELATIVE,
        );
    }

    /**
     * Counts every SKILL.md under $dir at any depth — real corpora nest (this machine's own
     * ~/.claude/skills/anthropics-skills/skills/claude-api/SKILL.md is two levels down), and
     * this count feeds the one mandated user-facing trust-boundary message, so a one-level-only
     * count can print a reassuring "Skipped 0" while a nested corpus was actually present.
     * Symlinks are NOT followed: this walks a REFUSED, untrusted project directory purely to
     * count filenames, and there is no reason to chase a link a hostile repo controls.
     */
    private static function countSkillFiles(string $dir): int
    {
        $count = 0;
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if ($file->getFilename() === 'SKILL.md') {
                $count++;
            }
        }

        return $count;
    }

    /**
     * Resolved, existing SKILL.md paths directly under ~/.paider/skills/*.
     *
     * @return array<int, string>
     */
    private static function homeSkillFiles(): array
    {
        $home = getenv('HOME');

        if ($home === false || $home === '') {
            return [];
        }

        $matches = glob($home.'/'.self::HOME_RELATIVE.'/*/SKILL.md') ?: [];
        $resolved = [];

        foreach ($matches as $path) {
            // A NUL can never be part of a legitimate path — same fail-closed discipline as
            // PathGuard::containedIn(), applied here to whatever a crafted symlink reports.
            if (str_contains($path, "\0")) {
                continue;
            }

            // ~/.paider/skills is user-owned and user-trusted, and roughly half the real corpus
            // is symlinked in from elsewhere (measured: 104/216) — realpath() follows the
            // symlink so the target actually gets read, and drops the entry only when the
            // target is missing (a dangling symlink), never by refusing symlinks outright.
            $real = realpath($path);

            if ($real !== false) {
                $resolved[] = $real;
            }
        }

        return $resolved;
    }

    /** @return array{name: string, description: string}|null */
    private static function parseFile(string $path): ?array
    {
        $contents = @file_get_contents($path);

        if ($contents === false) {
            return null;
        }

        $parsed = Frontmatter::parse($contents);

        return $parsed['error'] === null
            ? ['name' => $parsed['name'], 'description' => $parsed['description']]
            : null;
    }

    /**
     * A block-scalar frontmatter value can legally contain newlines (Frontmatter::parseBlock
     * preserves them for '|', folds paragraph breaks for '>') — but Loop renders one line per
     * index entry ("- {name}: {description}"), so an unstripped newline lets a single skill
     * forge extra lines that read as additional, unrelated index entries. Collapsed to a single
     * space before the length cap is applied — same fix on both name and description, since
     * both ride in that one rendered line.
     */
    private static function truncateField(string $value, int $max): string
    {
        $value = trim(preg_replace('/\s+/', ' ', $value) ?? $value);

        return mb_strlen($value) > $max ? mb_substr($value, 0, $max).'…' : $value;
    }

    /** Everything after the closing '---' — the frontmatter is already known-valid at this call site. */
    private static function bodyAfterFrontmatter(string $contents): string
    {
        $lines = preg_split('/\r\n|\r|\n/', $contents);
        $closing = null;

        for ($i = 1, $count = count($lines); $i < $count; $i++) {
            if ($lines[$i] === '---') {
                $closing = $i;
                break;
            }
        }

        // load() only reaches here after Frontmatter::parse succeeded on the same $contents,
        // which already required a closing delimiter to exist — $closing cannot be null.
        return ltrim(implode("\n", array_slice($lines, $closing + 1)), "\n");
    }

    /** @return array<int, string> */
    private static function unavailableTools(string $body): array
    {
        $found = [];
        $total = 0;

        foreach (self::UNAVAILABLE_PATTERNS as $label => $pattern) {
            $count = preg_match_all($pattern, $body);

            if ($count > 0) {
                $found[] = $label;
                $total += $count;
            }
        }

        // Matches the census threshold that motivated this warning in the first place: the
        // real corpus measured 18% of skills as "structurally Claude-dependent" at >=3
        // references, versus 42% that merely mention one of these words once — often generic
        // prose like "build artifacts" that has nothing to do with the Claude artifacts
        // feature. Gating the whole warning on the same >=3 threshold keeps it meaningful
        // instead of training the model to ignore it.
        return $total >= 3 ? $found : [];
    }
}
