<?php

namespace App\Support;

/**
 * Builds a scrubbed environment array for proc_open() calls that must NOT inherit the full
 * parent process environment (which includes live provider API keys — see DECISIONS.md §17).
 * Default is an allowlist of names known to be needed for a shell/git child to function, not a
 * denylist of secret-shaped names (a denylist always misses one). A user opts an extra variable
 * back in via the PAIDER_SHELL_ENV_ALLOW env var: a comma-separated list of additional names to
 * pass through, read fresh on every call so it can be changed per-session without restarting.
 */
class ShellEnv
{
    private const ALLOWLIST = ['PATH', 'HOME', 'LANG', 'TERM', 'TMPDIR', 'USER', 'SHELL'];

    public static function build(): array
    {
        $names = self::ALLOWLIST;

        $extra = getenv('PAIDER_SHELL_ENV_ALLOW');

        if (is_string($extra) && $extra !== '') {
            foreach (explode(',', $extra) as $name) {
                $name = trim($name);

                if ($name !== '') {
                    $names[] = $name;
                }
            }
        }

        $env = [];

        foreach (array_unique($names) as $name) {
            $value = getenv($name);

            if ($value !== false) {
                $env[$name] = $value;
            }
        }

        return $env;
    }
}
