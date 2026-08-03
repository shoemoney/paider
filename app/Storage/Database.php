<?php

namespace App\Storage;

use PDO;

class Database
{
    /**
     * Open (creating if necessary) the project-scoped SQLite database and apply the
     * pragmas STORAGE.md locks in. page_size MUST run before any table exists to take
     * effect on a fresh file, so it goes first and callers must not create tables before
     * calling this.
     */
    public static function connect(?string $path = null): PDO
    {
        $path ??= getcwd().'/.paider/paider.db';

        if ($path !== ':memory:') {
            $dir = dirname($path);

            if (! is_dir($dir) && ! mkdir($dir, 0755, true) && ! is_dir($dir)) {
                throw new \RuntimeException("Unable to create storage directory: {$dir}");
            }
        }

        $pdo = new PDO('sqlite:'.$path);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $pdo->exec('PRAGMA page_size=4096;');
        $pdo->exec('PRAGMA journal_mode=WAL;');
        $pdo->exec('PRAGMA synchronous=NORMAL;');
        $pdo->exec('PRAGMA busy_timeout=5000;');

        return $pdo;
    }
}
