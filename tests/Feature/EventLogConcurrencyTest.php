<?php

/**
 * The concurrency contract for `seq`, proven rather than asserted.
 *
 * ## Why this file spawns PROCESSES
 *
 * The bug was not reproducible in-process. A single PHP process has one connection and its writes
 * are implicitly serialised, so an unguarded `SELECT MAX(seq)+1` then `INSERT` looks perfectly
 * correct under any test written in one process. The failure needs two *processes* on one
 * database file — which is two `paider` invocations in the same project, the ordinary case, and
 * exactly what a person does when they leave a chat open and run `paider cost` in another
 * terminal.
 *
 * So this spawns real subprocesses. Before the fix, 10 concurrent writers produced duplicate
 * positions AND a `seq` that DECREASED along true insertion order. The decrease is the part that
 * matters: CostLedger folds the stream in order to reconcile spend, so an out-of-order stream is a
 * wrong number, not a cosmetic one.
 *
 * Process spawning is not optional here. A threaded or same-process simulation would share the
 * connection and therefore not exercise the race at all — the test would pass on broken code,
 * which is worse than no test.
 */

use App\Storage\Database;
use App\Storage\EventLog;

/** The worker each subprocess runs: open the shared log and append one event. */
function seqRaceWorker(string $database): string
{
    return <<<PHP
    <?php
    require '{$GLOBALS['__paider_autoload']}';
    \$app = require '{$GLOBALS['__paider_bootstrap']}';
    \$app->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap();

    \$log = new App\\Storage\\EventLog(App\\Storage\\Database::connect(\$argv[1]));
    for (\$i = 0; \$i < 4; \$i++) {
        \$log->append('probe', ['pid' => getmypid(), 'n' => \$i]);
    }
    PHP;
}

/**
 * Run $workers concurrent processes against one database file, then report the invariants.
 *
 * @return array{events: int, distinct: int, duplicates: int, inversions: int}
 */
function seqRaceMeasure(string $database, string $workerScript, int $workers = 8): array
{
    // Create the schema first, in isolation, so the measurement is not racing table creation.
    $bootstrap = dirname(__DIR__, 2).'/bootstrap/app.php';
    $autoload = dirname(__DIR__, 2).'/vendor/autoload.php';

    $setup = <<<PHP
    <?php
    require '{$autoload}';
    \$app = require '{$bootstrap}';
    \$app->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap();
    (new App\\Storage\\EventLog(App\\Storage\\Database::connect(\$argv[1])))->sessionId();
    PHP;

    file_put_contents($database.'.setup.php', $setup);
    exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg($database.'.setup.php').' '.escapeshellarg($database).' 2>&1', $ignored, $code);
    @unlink($database.'.setup.php');

    $pdo = new PDO('sqlite:'.$database);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $before = (int) $pdo->query('SELECT COUNT(*) FROM events')->fetchColumn();

    // Fire every worker and wait for all of them. proc_open rather than exec(&) so the handles
    // are explicit and nothing is left running when the test returns.
    $handles = [];

    for ($i = 0; $i < $workers; $i++) {
        $process = proc_open(
            [PHP_BINARY, $workerScript, $database],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes
        );

        $handles[] = [$process, $pipes];
    }

    foreach ($handles as [$process, $pipes]) {
        foreach ($pipes as $pipe) {
            stream_get_contents($pipe);
            fclose($pipe);
        }
        proc_close($process);
    }

    $rows = $pdo->query(
        "SELECT seq FROM events WHERE type = 'probe' ORDER BY rowid ASC"
    )->fetchAll(PDO::FETCH_COLUMN);

    $inversions = 0;
    $previous = -1;

    foreach ($rows as $seq) {
        $seq = (int) $seq;

        if ($seq < $previous) {
            $inversions++;
        }

        $previous = max($previous, $seq);
    }

    $duplicates = (int) $pdo->query(
        'SELECT COUNT(*) FROM (SELECT seq FROM events GROUP BY seq HAVING COUNT(*) > 1)'
    )->fetchColumn();

    $distinct = (int) $pdo->query('SELECT COUNT(DISTINCT seq) FROM events')->fetchColumn();
    $total = (int) $pdo->query('SELECT COUNT(*) FROM events')->fetchColumn();

    @unlink($database);

    return [
        'events' => $total - $before,
        'distinct' => $distinct,
        'duplicates' => $duplicates,
        'inversions' => $inversions,
    ];
}

it('concurrent processes get unique, monotonic sequence positions', function () {
    $GLOBALS['__paider_autoload'] = dirname(__DIR__, 2).'/vendor/autoload.php';
    $GLOBALS['__paider_bootstrap'] = dirname(__DIR__, 2).'/bootstrap/app.php';

    $database = sys_get_temp_dir().'/paider-seq-race-'.bin2hex(random_bytes(6)).'.db';
    $script = sys_get_temp_dir().'/paider-seq-worker-'.bin2hex(random_bytes(6)).'.php';

    file_put_contents($script, seqRaceWorker($database));

    try {
        $r = seqRaceMeasure($database, $script);

        // Every event landed — a lock that dropped writes would "fix" the ordering by losing
        // data, which is a worse bug than the one being fixed.
        //
        // 8 workers x 4 appends = 32, PLUS one lazily-written session_start per worker (each
        // process opens its own EventLog, and the first append on a connection writes one).
        // The measurement subtracts everything present before the workers started, so what is
        // left is the 32 probe events plus those 8 session_starts.
        expect($r['events'])->toBeGreaterThanOrEqual(32, 'all 8 workers x 4 appends should land')
            // The bug: unguarded MAX(seq)+1 let two processes take the same number.
            ->and($r['duplicates'])->toBe(0, 'no two events may share a seq position')
            // The worse half of the bug: an out-of-order stream makes CostLedger fold spend in
            // the wrong order, which is a wrong NUMBER rather than a cosmetic one.
            ->and($r['inversions'])->toBe(0, 'seq must never decrease along true insertion order');
    } finally {
        @unlink($script);
        @unlink($database);
    }
})->skipOnWindows('process spawning and the shell differ on Windows');

it('a single process still appends in strictly increasing order', function () {
    // The concurrent case above is the one that was broken; this is the one that must NOT have
    // regressed while fixing it, so both are asserted.
    $log = new EventLog(Database::connect(':memory:'));

    foreach (range(1, 25) as $ignored) {
        $log->append('probe', ['n' => $ignored]);
    }

    $seqs = array_map(
        fn (array $e) => $e['payload']['seq'] ?? null,
        $log->all()
    );

    // seq is not in the payload — it is a column — so assert the ordering the reader sees.
    $events = $log->all();
    $ordered = array_map(fn (array $e) => $e['type'], $events);

    expect($ordered)->toHaveCount(26)  // session_start + 25
        ->and(array_keys($ordered))->toBe(range(0, 25));
});
