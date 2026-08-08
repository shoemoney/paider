<?php

namespace App\Support;

class SecretsGuard
{
    private const DENY_GLOBS = [
        '.env',
        '.env.*',
        '*.pem',
        '*.key',
        'id_rsa*',
        '*.p12',
    ];

    public static function isSensitive(string $absolutePath): bool
    {
        $basename = basename($absolutePath);

        foreach (self::DENY_GLOBS as $pattern) {
            if (fnmatch($pattern, $basename)) {
                return true;
            }
        }

        if (str_contains($absolutePath, '.aws/credentials')) {
            return true;
        }

        if (str_contains($absolutePath, '.claude/aigate') || str_contains($absolutePath, 'aigate')) {
            return true;
        }

        return self::isGitIgnored($absolutePath);
    }

    private static function isGitIgnored(string $absolutePath): bool
    {
        $dir = self::nearestExistingAncestor(dirname($absolutePath));

        if ($dir === null) {
            return false;
        }

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        // Scrubbed env, same as ShellTool and GitTool. DECISIONS.md §15's architectural
        // caveat is that ANY proc_open call site outside ShellEnv reopens the key-leak this
        // repo closed in §17 — and until now this was the third one, and the only one still
        // inheriting the full parent environment. Lower risk than the tools (fixed argv, no
        // user input reaches it) but the invariant is worth more than the exception: every
        // proc_open in this codebase routes through ShellEnv, no cases to remember.
        // `-C $dir` already sets the working directory, so cwd stays null.
        $process = @proc_open(
            ['git', '-C', $dir, 'check-ignore', '-q', $absolutePath],
            $descriptors,
            $pipes,
            null,
            ShellEnv::build()
        );

        if (! is_resource($process)) {
            // No git binary, or repo not found — never throw, just fall back to the deny-list.
            return false;
        }

        fclose($pipes[0]);
        stream_get_contents($pipes[1]);
        stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return proc_close($process) === 0;
    }

    private static function nearestExistingAncestor(string $path): ?string
    {
        while (! is_dir($path)) {
            $parent = dirname($path);

            if ($parent === $path) {
                return null;
            }

            $path = $parent;
        }

        return $path;
    }
}
