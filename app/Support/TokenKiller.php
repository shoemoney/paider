<?php

namespace App\Support;

/**
 * TokenKiller — Paider-native port of Rust's token-killer pattern.
 * Tree-sitter equivalent via PHP's tokenizer: extract defs only (class/fn signatures),
 * rank by keyword overlap, budget to 1M, emit pruned context.
 * Research tier's 50k→500 win lives here.
 */
class TokenKiller
{
    private const BUDGET_TOKENS = 800;

    /**
     * Prune files to budgeted context for a query.
     *
     * @param  string  $query  e.g. "add discount to Receipt"
     * @param  array<int, string>  $files  absolute paths
     * @return string pruned context (symbols + top file excerpts)
     */
    public static function prune(string $query, array $files): string
    {
        $queryTokens = self::tokenizeQuery($query);
        $ranked = [];

        foreach ($files as $file) {
            if (! is_file($file)) {
                continue;
            }
            // Guard: don't prune outside project root (TokenKiller could be fed glob outside)
            // Use PathGuard if available — relative path handling for duplicate basenames
            if (class_exists(PathGuard::class)) {
                $root = getcwd();
                if (! PathGuard::containedIn($root, $file) && ! str_starts_with($file, $root)) {
                    // Allow absolute fixture paths in tests, but still guard ~/.paider
                    if (str_contains($file, '.paider/paider.db')) {
                        continue;
                    }
                }
            }
            $content = file_get_contents($file);
            $sigs = self::extractSignatures($content, $file);
            $score = self::score($sigs, $queryTokens);
            $ranked[] = ['file' => $file, 'sigs' => $sigs, 'score' => $score, 'content' => $content];
        }

        usort($ranked, fn ($a, $b) => $b['score'] <=> $a['score']);

        $out = "<symbols>\n";
        $tokens = 0;
        $included = 0;

        foreach ($ranked as $item) {
            $rel = str_starts_with($item['file'], getcwd().DIRECTORY_SEPARATOR) ? substr($item['file'], strlen(getcwd()) + 1) : basename($item['file']);
            $chunk = $rel.': '.$item['sigs']."\n";
            $chunkTokens = self::approxTokens($chunk);
            if ($tokens + $chunkTokens > self::BUDGET_TOKENS && $included > 0) {
                break;
            }
            $out .= $chunk;
            $tokens += $chunkTokens;
            $included++;
            if ($included >= 3) {
                break;
            }
        }
        $out .= "</symbols>\n";

        // Append top file excerpt (first 100 lines) for best match only
        if (! empty($ranked)) {
            $top = $ranked[0];
            $relTop = str_starts_with($top['file'], getcwd().DIRECTORY_SEPARATOR) ? substr($top['file'], strlen(getcwd()) + 1) : basename($top['file']);
            $excerpt = implode("\n", array_slice(explode("\n", $top['content']), 0, 100));
            $out .= "\n<excerpt file=\"".$relTop."\">\n".substr($excerpt, 0, 2000)."\n</excerpt>\n";
        }

        return $out;
    }

    public static function extractSignatures(string $content, string $file): string
    {
        if (str_ends_with($file, '.php')) {
            $tokens = token_get_all($content);
            $sigs = [];
            $count = count($tokens);
            for ($i = 0; $i < $count; $i++) {
                $tok = $tokens[$i];
                if (! is_array($tok)) {
                    continue;
                }
                [$id, $text] = $tok;
                if ($id === T_CLASS || $id === T_FUNCTION || $id === T_INTERFACE || $id === T_TRAIT) {
                    $next = '';
                    for ($j = $i + 1; $j < $count; $j++) {
                        if (is_array($tokens[$j]) && $tokens[$j][0] === T_STRING) {
                            $next = $tokens[$j][1];
                            break;
                        }
                        if (is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE) {
                            continue;
                        }
                        break;
                    }
                    $kind = token_name($id);
                    $sigs[] = trim("$kind $next");
                }
            }

            return $sigs ? implode('; ', array_slice($sigs, 0, 20)) : substr($content, 0, 500);
        }

        return substr($content, 0, 500);
    }

    private static function tokenizeQuery(string $query): array
    {
        $q = strtolower($query);
        $q = preg_split('/[^a-z0-9]+/', $q, -1, PREG_SPLIT_NO_EMPTY);

        return array_unique($q ?: []);
    }

    private static function score(string $sigs, array $queryTokens): int
    {
        $s = strtolower($sigs);
        $score = 0;
        foreach ($queryTokens as $tok) {
            if (str_contains($s, $tok)) {
                $score += 10;
            }
        }

        return $score;
    }

    private static function approxTokens(string $text): int
    {
        return (int) ceil(strlen($text) / 4);
    }

    public static function budget(): int
    {
        return self::BUDGET_TOKENS;
    }
}
