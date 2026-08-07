<?php

namespace App\Skills;

/**
 * Parses the '---' delimited frontmatter of a SKILL.md down to exactly two keys: name and
 * description. No YAML library — ext-yaml is absent and staying absent (EXTENSIONS.md), and a
 * skill's frontmatter only ever needs two scalars out of it.
 *
 * The description is the ONLY routing signal the model gets to decide whether a skill applies.
 * A skill parsed to a wrong or truncated description never triggers, silently, with no visible
 * error — so every ambiguous case here refuses with a reason instead of guessing. Same register
 * as Loop::parseToolCall.
 *
 * Quoting is decided by the FIRST non-space character after the colon, never a substring scan.
 * A real file on disk contains `description: ">"` — a naive scan for '>' would treat that quoted
 * literal as a block-scalar opener and swallow every key after it.
 */
final class Frontmatter
{
    private const BLOCK_INDICATORS = ['>', '>-', '>+', '|', '|-', '|+'];

    /** @return array{name: ?string, description: ?string, error: ?string} */
    public static function parse(string $contents): array
    {
        $lines = preg_split('/\r\n|\r|\n/', $contents);

        if (($lines[0] ?? null) !== '---') {
            return self::fail('missing frontmatter opening delimiter');
        }

        $closing = null;

        for ($i = 1, $count = count($lines); $i < $count; $i++) {
            if ($lines[$i] === '---') {
                $closing = $i;
                break;
            }
        }

        if ($closing === null) {
            return self::fail('missing frontmatter closing delimiter');
        }

        $body = array_slice($lines, 1, $closing - 1);

        // Tabs make indentation depth ambiguous (a tab is not a fixed number of the spaces the
        // rest of this parser counts in), so a continuation's boundary — and therefore whether
        // it belongs to the key above it — could be misread. Refuse rather than guess.
        foreach ($body as $line) {
            preg_match('/^[ \t]*/', $line, $m);

            if (str_contains($m[0], "\t")) {
                return self::fail('tab indentation');
            }
        }

        $name = null;
        $description = null;

        for ($i = 0, $count = count($body); $i < $count; $i++) {
            // Anchored at the start of the string, so an indented line (a continuation, a list
            // item, a nested key) can never match — only a real top-level `key:` does.
            if (! preg_match('/^([A-Za-z_][A-Za-z0-9_-]*):(.*)$/', $body[$i], $m)) {
                continue;
            }

            $key = $m[1];

            if ($key !== 'name' && $key !== 'description') {
                continue;
            }

            if (($key === 'name' && $name !== null) || ($key === 'description' && $description !== null)) {
                return self::fail("duplicate {$key} key");
            }

            [$value, $lastConsumed, $error] = self::parseScalar($m[2], $body, $i);

            if ($error !== null) {
                return self::fail($error);
            }

            if ($key === 'name') {
                $name = $value;
            } else {
                $description = $value;
            }

            $i = $lastConsumed;
        }

        if ($name === null) {
            return self::fail('missing required key: name');
        }

        if ($description === null) {
            return self::fail('missing required key: description');
        }

        return ['name' => $name, 'description' => $description, 'error' => null];
    }

    /**
     * @param  array<int, string>  $body
     * @return array{0: ?string, 1: int, 2: ?string} value, index of the last line consumed, error
     */
    private static function parseScalar(string $rest, array $body, int $i): array
    {
        // Leading spaces only — a leading tab was already refused above, and the decision below
        // must key on the first non-space character, never a substring scan of the whole value.
        $afterSpaces = ltrim($rest, ' ');
        $first = $afterSpaces === '' ? null : $afterSpaces[0];

        if ($first === '"' || $first === "'") {
            [$value, $error] = self::parseQuoted(substr($afterSpaces, 1), $first);

            return [$value, $i, $error];
        }

        $token = rtrim($afterSpaces);

        if (in_array($token, self::BLOCK_INDICATORS, true)) {
            return self::parseBlock($token[0], $body, $i);
        }

        // A plain (non-block) scalar owns exactly one line — a following indented line is a
        // continuation with no block opener to interpret it, the same ambiguity refused below
        // for an empty value. Checked once, ahead of the empty/non-empty split, so a NON-empty
        // scalar can no longer fall straight through to the return at the bottom and silently
        // swallow — rather than refuse — the indented line after it.
        $next = $body[$i + 1] ?? null;
        $hasIndentedContinuation = $next !== null && $next !== '' && $next[0] === ' ';

        if ($hasIndentedContinuation) {
            return [null, $i, 'indented continuation with no block opener'];
        }

        return $afterSpaces === '' ? ['', $i, null] : [rtrim($afterSpaces), $i, null];
    }

    /** @return array{0: ?string, 1: ?string} value, error */
    private static function parseQuoted(string $body, string $quote): array
    {
        $result = '';

        for ($p = 0, $len = strlen($body); $p < $len; $p++) {
            $ch = $body[$p];

            if ($ch === '\\') {
                // YAML escapes are a whole grammar of their own (\n, \t, \uXXXX...) that this
                // parser does not implement — silently passing the backslash through would
                // hand the model a description containing a literal backslash-n instead of a
                // newline. Refuse rather than mis-decode.
                return [null, 'backslash escape inside a quoted value'];
            }

            if ($ch === $quote) {
                // Single-quoted YAML escapes an embedded quote by doubling it: 'it''s fine'.
                if ($quote === "'" && ($body[$p + 1] ?? '') === "'") {
                    $result .= "'";
                    $p++;

                    continue;
                }

                return [$result, null];
            }

            $result .= $ch;
        }

        return [null, 'unterminated quote'];
    }

    /**
     * @param  array<int, string>  $body
     * @return array{0: ?string, 1: int, 2: ?string} value, index of the last line consumed, error
     */
    private static function parseBlock(string $indicator, array $body, int $i): array
    {
        $collected = [];
        $baseIndent = null;
        $j = $i + 1;

        for ($count = count($body); $j < $count; $j++) {
            $line = $body[$j];

            if ($line === '') {
                $collected[] = '';

                continue;
            }

            if (! preg_match('/^( +)/', $line, $m)) {
                break; // dedented — the block ended, this line belongs to whatever comes next
            }

            $baseIndent ??= strlen($m[1]);

            if (strlen($m[1]) < $baseIndent) {
                break;
            }

            $collected[] = substr($line, $baseIndent);
        }

        while ($collected !== [] && end($collected) === '') {
            array_pop($collected);
        }

        // '>' (folded) joins with spaces, but a blank line inside the block is still a
        // paragraph break, not a space — fold each run of non-blank lines, then join those
        // paragraphs with a newline. '|' (literal) keeps every line break as-is.
        if ($indicator === '>') {
            $paragraphs = [];
            $current = [];

            foreach ($collected as $line) {
                if ($line === '') {
                    if ($current !== []) {
                        $paragraphs[] = implode(' ', $current);
                        $current = [];
                    }

                    continue;
                }

                $current[] = $line;
            }

            if ($current !== []) {
                $paragraphs[] = implode(' ', $current);
            }

            $value = implode("\n", $paragraphs);
        } else {
            $value = implode("\n", $collected);
        }

        // Exact chomping (clip/strip/keep trailing newlines) doesn't matter for a routing
        // description — only that the continuation boundary was found correctly.
        return [trim($value), $j - 1, null];
    }

    /** @return array{name: null, description: null, error: string} */
    private static function fail(string $reason): array
    {
        return ['name' => null, 'description' => null, 'error' => $reason];
    }
}
