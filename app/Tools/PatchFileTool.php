<?php

namespace App\Tools;

use App\Support\PathGuard;
use App\Tools\Contracts\Tool;

class PatchFileTool implements Tool
{
    public const NEW_FILE_STAMP = '__new_file__';

    public function __construct(
        private readonly string $root,
    ) {}

    public function name(): string
    {
        return 'patch_file';
    }

    public function description(): string
    {
        return 'Apply a unified diff to a file, gated by a content-hash stamp and (for .php files) a syntax check.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'path' => ['type' => 'string'],
                'diff' => ['type' => 'string'],
                'stamp' => ['type' => 'string'],
            ],
            'required' => ['path', 'diff', 'stamp'],
        ];
    }

    public function execute(array $input): ToolResult
    {
        $path = $input['path'] ?? null;
        $diff = $input['diff'] ?? null;
        $stamp = $input['stamp'] ?? null;

        if (! is_string($path) || $path === '' || ! is_string($diff) || ! is_string($stamp)) {
            return ToolResult::fail('path, diff, and stamp are required strings', ['invalid_input' => true]);
        }

        $absolute = str_starts_with($path, DIRECTORY_SEPARATOR)
            ? $path
            : $this->root.DIRECTORY_SEPARATOR.$path;

        if (! PathGuard::containedIn($this->root, $absolute)) {
            return ToolResult::fail('path escapes the workspace root', ['denied' => true]);
        }

        $exists = is_file($absolute);

        if ($exists) {
            $current = file_get_contents($absolute);

            if ($current === false) {
                return ToolResult::fail('could not read target file', ['io_error' => true]);
            }

            if (! hash_equals(hash('sha256', $current), $stamp)) {
                return ToolResult::fail('file changed since it was added to context', ['conflict' => 'stale_stamp']);
            }
        } else {
            $current = '';

            if ($stamp !== self::NEW_FILE_STAMP) {
                return ToolResult::fail('file does not exist and stamp is not __new_file__', ['conflict' => 'stale_stamp']);
            }
        }

        $eol = $this->dominantEol($current, $exists);

        $hunks = $this->parseHunks($diff, $eol);

        if ($hunks instanceof ToolResult) {
            return $hunks;
        }

        $newContent = $this->applyHunks($current, $hunks, $eol);

        if ($newContent instanceof ToolResult) {
            return $newContent;
        }

        if (str_ends_with($path, '.php')) {
            try {
                token_get_all($newContent, TOKEN_PARSE);
            } catch (\ParseError $e) {
                return ToolResult::fail('patched content is not valid PHP', [
                    'syntax_error' => true,
                    'detail' => $e->getMessage(),
                ]);
            }
        }

        $dir = dirname($absolute);

        if (! is_dir($dir) && ! mkdir($dir, 0777, true) && ! is_dir($dir)) {
            return ToolResult::fail('could not create parent directory', ['io_error' => true]);
        }

        $tmp = $dir.DIRECTORY_SEPARATOR.'.'.basename($absolute).'.'.bin2hex(random_bytes(6)).'.tmp';

        if (file_put_contents($tmp, $newContent) === false) {
            return ToolResult::fail('could not write temp file', ['io_error' => true]);
        }

        if (! rename($tmp, $absolute)) {
            @unlink($tmp);

            return ToolResult::fail('could not finalize write', ['io_error' => true]);
        }

        return ToolResult::ok('patched '.$path, [
            'path' => $path,
            'bytes' => strlen($newContent),
            'sha256' => hash('sha256', $newContent),
        ]);
    }

    /**
     * Detects the file's dominant line ending. New files ('__new_file__') have no content to
     * inspect yet, so the diff's own ending (checked line-by-line below) is what matters there —
     * default to "\n" purely so this function always returns something.
     */
    private function dominantEol(string $content, bool $exists): string
    {
        if (! $exists) {
            return "\n";
        }

        $crlf = substr_count($content, "\r\n");
        $lf = substr_count($content, "\n") - $crlf;

        return $crlf > $lf ? "\r\n" : "\n";
    }

    /**
     * Returns an array of hunks, each ['old_start' => int, 'lines' => [['type' => ' '|'+'|'-', 'text' => string]]],
     * or a ToolResult::fail on any parse problem.
     */
    private function parseHunks(string $diff, string $eol): array|ToolResult
    {
        if (trim($diff) === '') {
            return ToolResult::fail('empty diff', ['parse_error' => true, 'detail' => 'diff is empty']);
        }

        // Reject mismatched line endings before splitting: a diff written with bare \n against a
        // \r\n file (or vice versa) must not be silently mixed into the output.
        $hasCrlf = str_contains($diff, "\r\n");
        $hasBareLf = (bool) preg_match('/(?<!\r)\n/', $diff);

        if ($eol === "\r\n" && $hasBareLf) {
            return ToolResult::fail('diff line endings do not match file', [
                'parse_error' => true,
                'detail' => 'file uses CRLF but diff contains bare LF lines',
            ]);
        }

        if ($eol === "\n" && $hasCrlf) {
            return ToolResult::fail('diff line endings do not match file', [
                'parse_error' => true,
                'detail' => 'file uses LF but diff contains CRLF lines',
            ]);
        }

        $lines = preg_split('/\r\n|\n/', $diff);

        if ($lines !== [] && end($lines) === '') {
            array_pop($lines);
        }

        $hunks = [];
        $i = 0;
        $n = count($lines);

        // Skip optional file-header lines (---/+++), unified-diff bodies only need the @@ hunks.
        while ($i < $n && (str_starts_with($lines[$i], '---') || str_starts_with($lines[$i], '+++'))) {
            $i++;
        }

        if ($i >= $n) {
            return ToolResult::fail('diff has no hunks', ['parse_error' => true, 'detail' => 'no @@ hunk header found']);
        }

        while ($i < $n) {
            $header = $lines[$i];

            if (! preg_match('/^@@ -(\d+)(?:,(\d+))? \+(\d+)(?:,(\d+))? @@/', $header, $m)) {
                return ToolResult::fail('malformed hunk header', [
                    'parse_error' => true,
                    'detail' => "expected '@@ -a,b +c,d @@', got: ".$header,
                ]);
            }

            $oldStart = (int) $m[1];
            $i++;

            $hunkLines = [];

            while ($i < $n && ! str_starts_with($lines[$i], '@@ ')) {
                $line = $lines[$i];

                if ($line === '') {
                    // A blank line only ever means "context line with empty content" in a
                    // well-formed diff — but without a leading space we can't tell that apart
                    // from a malformed line, so reject rather than guess.
                    return ToolResult::fail('malformed diff line', [
                        'parse_error' => true,
                        'detail' => 'blank line with no leading " "/"+"/"-" marker',
                    ]);
                }

                $marker = $line[0];

                // '\ No newline at end of file' tags the immediately preceding body line as
                // lacking a trailing EOL on the side that line belongs to. It is not itself a
                // content line, so fold it into the previous entry instead of storing it.
                if ($marker === '\\') {
                    if ($hunkLines === []) {
                        return ToolResult::fail('malformed diff line', [
                            'parse_error' => true,
                            'detail' => "'\\ No newline at end of file' marker with no preceding line",
                        ]);
                    }

                    $hunkLines[count($hunkLines) - 1]['no_eol'] = true;
                    $i++;

                    continue;
                }

                if ($marker !== ' ' && $marker !== '+' && $marker !== '-') {
                    return ToolResult::fail('malformed diff line', [
                        'parse_error' => true,
                        'detail' => "line does not start with ' ', '+', or '-': ".$line,
                    ]);
                }

                $hunkLines[] = ['type' => $marker, 'text' => substr($line, 1), 'no_eol' => false];
                $i++;
            }

            if ($hunkLines === []) {
                return ToolResult::fail('hunk has no body', ['parse_error' => true, 'detail' => 'hunk header followed by no lines']);
            }

            $hunks[] = ['old_start' => $oldStart, 'lines' => $hunkLines];
        }

        return $hunks;
    }

    /**
     * @param  array<int, array{old_start: int, lines: array<int, array{type: string, text: string}>}>  $hunks
     */
    private function applyHunks(string $current, array $hunks, string $eol): string|ToolResult
    {
        $currentLines = $current === '' ? [] : preg_split('/\r\n|\n/', $current);

        if ($currentLines !== [] && end($currentLines) === '' && str_ends_with($current, $eol)) {
            array_pop($currentLines);
        }

        // Whether the original file's own last line was terminated by $eol. Ground truth comes
        // from the file's bytes, not from the diff — a hand-crafted diff can omit the
        // '\ No newline at end of file' marker and we must still not invent a newline.
        $originalHadTrailingEol = $currentLines !== [] && str_ends_with($current, $eol);
        $lastOriginalIndex = count($currentLines) - 1;

        $result = [];
        $cursor = 0; // 0-indexed position in $currentLines already emitted into $result
        $lastEmittedHasEol = true; // trailing-EOL status of the most recently appended $result line

        foreach ($hunks as $hunk) {
            // '@@ -0,0 +c,d @@' is the standard convention for "insert at the very start of an
            // empty/new file" — old_start=0 means zero old lines, not a negative offset.
            $pos = $hunk['old_start'] === 0 ? 0 : $hunk['old_start'] - 1;

            if ($pos < 0 || $pos < $cursor) {
                return ToolResult::fail('hunk out of order or before start of file', [
                    'conflict' => true,
                    'detail' => 'hunk @-'.$hunk['old_start'].' overlaps or precedes prior hunk',
                ]);
            }

            // Copy untouched context between the previous hunk and this one verbatim.
            for (; $cursor < $pos; $cursor++) {
                if (! array_key_exists($cursor, $currentLines)) {
                    return ToolResult::fail('hunk start is past end of file', [
                        'conflict' => true,
                        'detail' => 'hunk claims to start at line '.($pos + 1).' but file has '.count($currentLines).' lines',
                    ]);
                }

                $result[] = $currentLines[$cursor];
                $lastEmittedHasEol = ! ($cursor === $lastOriginalIndex && ! $originalHadTrailingEol);
            }

            foreach ($hunk['lines'] as $line) {
                if ($line['type'] === '+') {
                    $result[] = $line['text'];
                    $lastEmittedHasEol = ! ($line['no_eol'] ?? false);

                    continue;
                }

                // ' ' (context) and '-' (deletion) both must match the current file at $cursor.
                if (! array_key_exists($cursor, $currentLines)) {
                    return ToolResult::fail('hunk references a line past end of file', [
                        'conflict' => true,
                        'detail' => 'expected line '.($cursor + 1).' but file has '.count($currentLines).' lines',
                    ]);
                }

                if ($currentLines[$cursor] !== $line['text']) {
                    return ToolResult::fail('context/deletion line does not match current file content', [
                        'conflict' => true,
                        'detail' => 'line '.($cursor + 1).' expected "'.$line['text'].'" but found "'.$currentLines[$cursor].'"',
                    ]);
                }

                if ($line['type'] === ' ') {
                    $result[] = $line['text'];
                    $lastEmittedHasEol = ! ($cursor === $lastOriginalIndex && ! $originalHadTrailingEol);
                }

                $cursor++;
            }
        }

        // Copy any remaining untouched tail.
        for (; $cursor < count($currentLines); $cursor++) {
            $result[] = $currentLines[$cursor];
            $lastEmittedHasEol = ! ($cursor === $lastOriginalIndex && ! $originalHadTrailingEol);
        }

        return implode($eol, $result).($result === [] ? '' : ($lastEmittedHasEol ? $eol : ''));
    }
}
