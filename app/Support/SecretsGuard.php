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

        $process = @proc_open(
            ['git', '-C', $dir, 'check-ignore', '-q', $absolutePath],
            $descriptors,
            $pipes
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
