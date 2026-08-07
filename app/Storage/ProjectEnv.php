<?php

namespace App\Storage;

use Dotenv\Dotenv;

/**
 * Configuration read from the project Paider is being RUN in, not the directory Paider is
 * installed in.
 *
 * Laravel Zero boots with `basePath: dirname(__DIR__)`, so its own dotenv reads the `.env`
 * sitting next to the installation — a global `composer global require` directory, or the phar.
 * Nothing in the user's project is ever consulted. Verified: with cwd set to a test project
 * holding `PAIDER_YOLO=1` in its `.env`, `env('PAIDER_YOLO')` resolved to NULL. Every setting
 * documented as "put it in your .env" was therefore silently inert for anyone who did not
 * happen to be running from a checkout of Paider itself.
 *
 * Precedence, highest first:
 *   1. a real environment variable (an explicit `FOO=1 paider chat` must always win)
 *   2. <project>/.paider/.env   — Paider's own settings, beside the database, already gitignored
 *   3. <project>/.env           — the project's existing file, for teams that keep one
 *
 * Nothing here is ever written back, and no value is exported into the process environment:
 * putenv() would leak project settings into every subprocess ShellTool spawns, which is exactly
 * what DECISIONS.md §17 scrubbed the child environment to prevent.
 */
class ProjectEnv
{
    /** @var array<string, string>|null */
    private static ?array $cache = null;

    /**
     * Read a setting the project is NOT allowed to decide for itself.
     *
     * Everything else here is deliberately project-scoped, which is right for preferences and
     * catastrophic for authority. A repository ships its own `.paider/.env`, so any setting
     * reachable through get() is a setting the REPOSITORY controls — and a repository
     * that can turn on auto-approval has granted itself unprompted shell execution the moment
     * someone clones it and runs Paider inside. Verified before this method existed: a
     * `.paider/.env` containing PAIDER_YOLO=1 made Gate::forSession(false) auto-approve with no
     * flag and no prompt, and PAIDER_FETCH_ALLOW opened a chosen private address.
     *
     * So authority comes from the real process environment only — the shell the human typed in,
     * which the cloned code cannot write to. Convenience settings may live in a project file;
     * permissions may not.
     */
    public static function fromEnvironment(string $key): ?string
    {
        $value = getenv($key);

        return $value === false || $value === '' ? null : $value;
    }

    public static function get(string $key, ?string $default = null): ?string
    {
        // A real environment variable outranks any file. `PAIDER_YOLO=1 paider chat` has to
        // beat a `.env` that says otherwise, or the flag becomes unpredictable.
        $live = getenv($key);

        if ($live !== false && $live !== '') {
            return $live;
        }

        return self::values()[$key] ?? $default;
    }

    /** @return array<string, string> */
    public static function values(): array
    {
        if (self::$cache !== null) {
            return self::$cache;
        }

        $root = getcwd() ?: '.';
        self::$cache = [];

        // Later files must not override earlier ones, so the more specific .paider/.env is
        // read first and the project-wide .env only fills gaps.
        foreach (['.paider/.env', '.env'] as $relative) {
            $path = $root.'/'.$relative;

            if (! is_file($path) || ! is_readable($path)) {
                continue;
            }

            try {
                // createArrayBacked: parses into an array WITHOUT touching $_ENV, $_SERVER or
                // putenv(), so nothing here becomes visible to a spawned subprocess.
                $parsed = Dotenv::createArrayBacked(dirname($path), basename($path))->load();
            } catch (\Throwable) {
                // A malformed .env must not make the tool unusable — it falls back to whatever
                // the real environment says, exactly like a missing file would.
                continue;
            }

            self::$cache += array_map('strval', array_filter($parsed, 'is_scalar'));
        }

        return self::$cache;
    }

    /** Test seam — the cache is process-lifetime, and getcwd() changes between tests. */
    public static function forget(): void
    {
        self::$cache = null;
    }
}
