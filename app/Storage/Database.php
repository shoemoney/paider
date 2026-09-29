<?php

namespace App\Storage;

/**
 * Driver seam for the event log.
 *
 * The default is UNCHANGED: one SQLite file, no services (see STORAGE.md). A `PAIDER_DATABASE_URL`
 * pointing at Postgres switches the same append-only log over, because the event log's design —
 * append-only, no UPDATE, no DELETE, TEXT id, JSON payload — is portable across both, and the
 * portability is the point: nothing above this class knows which one it got.
 *
 * Why the default stays SQLite. `composer require paider/paider` currently works with no database
 * server anywhere. Making Postgres mandatory would trade a stranger's first run for a capability
 * most projects do not need yet, which is the same trade DECISIONS.md §21 rejected for the
 * installer. With this seam they get both: zero-setup by default, shared/pgvector-backed on demand.
 *
 * Two things this class deliberately does NOT do: it does not fall back silently from a broken
 * Postgres URL to SQLite (that would turn "your database is unreachable" into "here are your last
 * 40 events, from a file you forgot about"), and it does not merge the two — a project is on one
 * driver or the other, decided once, and the log's contents are not portable between them.
 */
class Database
{
    public const DRIVER_SQLITE = 'sqlite';

    public const DRIVER_PGSQL = 'pgsql';

    /**
     * Open (creating if necessary) the project-scoped database and apply the driver's setup.
     *
     * `$path` is a SQLite path and is ignored when PAIDER_DATABASE_URL is set. ':memory:' is
     * honoured and FORCES SQLite even when the env var is present — tests rely on an isolated
     * in-memory log, and silently redirecting a test at a shared Postgres would make the suite
     * order-dependent and non-hermetic, which is the property this suite is most careful about.
     */
    public static function connect(?string $path = null): \PDO
    {
        $path ??= self::defaultSqlitePath();

        if ($path === ':memory:') {
            return self::connectSqlite($path);
        }

        $url = ProjectEnv::get('PAIDER_DATABASE_URL');

        if (is_string($url) && $url !== '') {
            return self::connectPostgres($url);
        }

        return self::connectSqlite($path);
    }

    /** The driver that connect() would choose, for reporting and for portable DDL. */
    public static function activeDriver(?string $path = null): string
    {
        if (($path ?? self::defaultSqlitePath()) === ':memory:') {
            return self::DRIVER_SQLITE;
        }

        $url = ProjectEnv::get('PAIDER_DATABASE_URL');

        return is_string($url) && $url !== '' ? self::DRIVER_PGSQL : self::DRIVER_SQLITE;
    }

    private static function defaultSqlitePath(): string
    {
        return getcwd().'/.paider/paider.db';
    }

    private static function connectSqlite(string $path): \PDO
    {
        if ($path !== ':memory:') {
            $dir = dirname($path);

            if (! is_dir($dir) && ! mkdir($dir, 0755, true) && ! is_dir($dir)) {
                throw new \RuntimeException("Unable to create storage directory: {$dir}");
            }
        }

        $pdo = new \PDO('sqlite:'.$path);
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

        // page_size MUST run before any table exists to take effect on a fresh file, so it goes
        // first and callers must not create tables before calling this.
        $pdo->exec('PRAGMA page_size=4096;');
        $pdo->exec('PRAGMA journal_mode=WAL;');
        $pdo->exec('PRAGMA synchronous=NORMAL;');
        $pdo->exec('PRAGMA busy_timeout=5000;');

        return $pdo;
    }

    /**
     * Postgres connection. The URL is parsed rather than handed to PDO as a DSN string so that a
     * malformed or hostile value fails HERE with a clear message, instead of surfacing later as
     * an opaque driver error. Credentials in a URL are normal (that is what a URL is for), so
     * unlike the mcpd endpoint this is not treated as a credential leak — but the error message
     * never echoes the password back.
     */
    private static function connectPostgres(string $url): \PDO
    {
        $parts = parse_url($url);

        if ($parts === false || ($parts['scheme'] ?? '') !== 'postgres') {
            throw new \RuntimeException('PAIDER_DATABASE_URL must be a postgres:// URL');
        }

        $host = $parts['host'] ?? '';
        $port = $parts['port'] ?? 5432;
        $user = isset($parts['user']) ? rawurldecode($parts['user']) : '';
        $password = isset($parts['pass']) ? rawurldecode($parts['pass']) : '';
        $database = isset($parts['path']) ? ltrim(rawurldecode($parts['path']), '/') : '';

        if ($host === '' || $user === '' || $database === '') {
            throw new \RuntimeException('PAIDER_DATABASE_URL needs a host, a user and a database name');
        }

        $pdo = new \PDO(
            sprintf('pgsql:host=%s;port=%d;dbname=%s', $host, $port, $database),
            $user,
            $password,
            [
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
            ]
        );

        // Append-only writes from a CLI that may be invoked concurrently (two terminals, a hook
        // and a chat session). Without this a reader can collide with a writer's transaction and
        // the collision surfaces as a serialization error rather than a retry.
        $pdo->exec('SET default_transaction_isolation TO "read committed"');

        return $pdo;
    }
}
