<?php

use App\Storage\Database;

it('creates the .paider directory under the cwd and applies the required pragmas', function () {
    $cwd = getcwd();
    $dir = sys_get_temp_dir().'/paider-db-test-'.uniqid();
    mkdir($dir);
    chdir($dir);

    try {
        $pdo = Database::connect();

        expect(is_dir($dir.'/.paider'))->toBeTrue()
            ->and(file_exists($dir.'/.paider/paider.db'))->toBeTrue();
    } finally {
        chdir($cwd);
    }

    expect($pdo->query('PRAGMA journal_mode')->fetchColumn())->toBe('wal')
        ->and((int) $pdo->query('PRAGMA busy_timeout')->fetchColumn())->toBe(5000);

    unset($pdo);
    array_map('unlink', glob($dir.'/.paider/*'));
    rmdir($dir.'/.paider');
    rmdir($dir);
});

it('applies journal_mode=wal, synchronous=NORMAL, busy_timeout=5000 and page_size=4096 on an explicit path', function () {
    $path = sys_get_temp_dir().'/paider-test-'.uniqid().'.db';

    $pdo = Database::connect($path);

    expect($pdo->query('PRAGMA journal_mode')->fetchColumn())->toBe('wal')
        ->and((int) $pdo->query('PRAGMA busy_timeout')->fetchColumn())->toBe(5000)
        ->and((int) $pdo->query('PRAGMA synchronous')->fetchColumn())->toBe(1) // NORMAL
        ->and((int) $pdo->query('PRAGMA page_size')->fetchColumn())->toBe(4096);

    unset($pdo);
    foreach (glob($path.'*') as $f) {
        unlink($f);
    }
});

it('connects to an in-memory database with the same pragmas', function () {
    $pdo = Database::connect(':memory:');

    expect((int) $pdo->query('PRAGMA busy_timeout')->fetchColumn())->toBe(5000);
});
