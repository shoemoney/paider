<?php

/**
 * The same event log, on Postgres, verified against a real server.
 *
 * This is the test that decides whether the driver seam is real or decorative. Every other
 * storage test in this suite runs on ':memory:' SQLite, which means the entire suite passing
 * says NOTHING about whether the port works — SQLite would happily accept `ORDER BY rowid` and
 * never mention that Postgres has no such column. That exact class of error (a driver-specific
 * construct that only the unused path would have caught) is the whole reason this file exists.
 *
 * Hermetic: it uses a SCHEMA inside a throwaway database, so it never collides with real data,
 * and it SKIPS rather than fails when no database is configured — a contributor without Docker
 * should get a skip, not a red suite. The skip is visible, and the full-suite count says so.
 *
 * Run against the disposable instance:
 *   PAIDER_TEST_PG_URL=postgres://postgres:paider@127.0.0.1:55432/paider_test vendor/bin/pest --group=pgsql
 */

use App\Storage\CostLedger;
use App\Storage\Database;
use App\Storage\EventLog;
use App\Storage\MemoryStore;
use App\Storage\SessionStore;

function pgsqlSchemaName(): string
{
    // Isolated per run so a crashed test cannot poison the next one.
    return 'paider_test_'.bin2hex(random_bytes(6));
}

/**
 * Build a live PDO on a private schema. Returns null when no test database is configured.
 */
function pgsqlConnection(?string &$schema): ?PDO
{
    $url = getenv('PAIDER_TEST_PG_URL');

    if (! is_string($url) || $url === '') {
        return null;
    }

    putenv('PAIDER_DATABASE_URL='.$url);

    try {
        $pdo = Database::connect();
    } finally {
        putenv('PAIDER_DATABASE_URL');
    }

    $schema = pgsqlSchemaName();
    $pdo->exec("CREATE SCHEMA {$schema}");
    // search_path scoped to this schema for the life of the connection, so a bare
    // `CREATE TABLE events` lands here and never in public.
    $pdo->exec("SET search_path TO {$schema}");

    return $pdo;
}

function pgsqlDropSchema(PDO $pdo, string $schema): void
{
    $pdo->exec("DROP SCHEMA IF EXISTS {$schema} CASCADE");
}

beforeEach(function () {
    $GLOBALS['__paider_pg'] = null;
    $this->pgSchema = null;
    $pg = pgsqlConnection($this->pgSchema);
    $GLOBALS['__paider_pg'] = $pg;
});

afterEach(function () {
    $pg = $GLOBALS['__paider_pg'] ?? null;

    if ($pg instanceof PDO && is_string($this->pgSchema)) {
        pgsqlDropSchema($pg, $this->pgSchema);
    }

    $GLOBALS['__paider_pg'] = null;
    putenv('PAIDER_DATABASE_URL');
});

/**
 * Require the live database, or SKIP.
 *
 * SKIP rather than fail, because a contributor without Docker must still get a green default
 * suite — a permanently red test is a test people delete or blanket-ignore, and a blanket-ignore
 * would take the SQLite coverage with it. Skipped tests are reported in the count, so "it passed"
 * and "it was never run" stay distinguishable.
 *
 * `markTestSkipped` is called INSIDE the test body, before any $pg dereference. The earlier
 * version asserted `$pg` is not null, which fails instead of skipping — the assertion
 * throws a failure, not a skip, and a test that cannot distinguish its own precondition is a test
 * that lies about why it went red.
 */
function requirePgsql(): PDO
{
    if (! ($GLOBALS['__paider_pg'] ?? null) instanceof PDO) {
        test()->markTestSkipped(
            'No PAIDER_TEST_PG_URL set — skipping the Postgres suite. '
            .'See tests/Feature/PostgresStorageTest.php for how to run it.'
        );
    }

    return $GLOBALS['__paider_pg'];
}

it('runs the whole storage stack on Postgres, not just the first insert', function () {
    $pg = requirePgsql();

    $log = new EventLog($pg);

    // Append-only, in order — the property CostLedger depends on.
    for ($i = 1; $i <= 5; $i++) {
        $log->append('tier_call', [
            'tier' => $i % 2 === 0 ? 'coder' : 'orchestrator',
            'model' => 'test/model',
            'tokens_in' => 100 * $i,
            'tokens_out' => 50 * $i,
            'cost_usd' => 0.001 * $i,
        ]);
    }

    $events = $log->all();
    // 5 appends PLUS the lazily-written session_start that EventLog emits before the first one.
    expect($events)->toHaveCount(6)
        ->and($events[0]['type'])->toBe('session_start');

    // THE port-critical assertion. `ORDER BY rowid` — the pre-port code — throws
    // "column rowid does not exist" here. If this test passes, the ordering really is
    // portable; if it is ever deleted rather than fixed, the port is quietly gone.
    $calls = array_slice($events, 1);
    expect(array_map(fn ($e) => $e['payload']['cost_usd'] ?? null, $calls))
        ->toBe([0.001, 0.002, 0.003, 0.004, 0.005]);
});

it('projects the same cost summary on Postgres as on SQLite', function () {
    $pg = requirePgsql();

    $log = new EventLog($pg);
    $log->append('tier_call', [
        'tier' => 'coder', 'model' => 'test/model',
        'tokens_in' => 1000, 'tokens_out' => 500, 'cost_usd' => 0.25,
    ]);
    $log->append('tier_call', [
        'tier' => 'coder', 'model' => 'test/model',
        'tokens_in' => 200, 'tokens_out' => 100, 'cost_usd' => 0.05,
    ]);

    $summary = (new CostLedger($log))->summary();
    $session = $summary['session'];

    // The ledger's flagship claim is that it reconciles against provider-reported usage.
    // If the two drivers disagree here, that claim is driver-dependent and the README is wrong.
    expect($session['spend_usd'])->toBe(0.30)
        ->and($session['tokens_in'])->toBe(1200)
        ->and($session['tokens_out'])->toBe(600)
        ->and($session['calls'])->toBe(2)
        ->and($session['unpriced_calls'])->toBe(0)
        ->and($summary['coder']['spend_usd'])->toBe(0.30);
});

it('round-trips memory and sessions on Postgres', function () {
    $pg = requirePgsql();

    $log = new EventLog($pg);

    // Facts are EVENTS, not a setter call — MemoryStore is a pure projection over the log, and
    // writing them this way is what actually exercises the stream ordering on both drivers.
    $log->append(MemoryStore::SET, ['key' => 'deploy target', 'value' => 'the pi swarm']);
    $log->append(MemoryStore::SET, ['key' => 'retired', 'value' => 'stale fact']);
    $log->append(MemoryStore::RETRACT, ['key' => 'retired']);

    $memory = new MemoryStore($log);
    expect($memory->all())->toBe(['deploy target' => 'the pi swarm'])
        ->and($memory->get('deploy target'))->toBe('the pi swarm')
        ->and($memory->get('retired'))->toBeNull();

    $log->append('session_message', ['role' => 'user', 'content' => 'hello from postgres']);
    $log->append('session_message', ['role' => 'assistant', 'content' => 'hi back']);

    $messages = (new SessionStore($log))->messages();
    expect($messages)->toHaveCount(2)
        ->and($messages[0]['content'])->toBe('hello from postgres');
});

it('migrates a pre-seq log without losing insertion order', function () {
    $pg = requirePgsql();

    // Build the OLD schema — no seq column — exactly as v0.1 shipped it, with rows already in
    // it. This is the upgrade path for every existing .paider/paider.db a user already has.
    $pg->exec(
        'CREATE TABLE events (id TEXT PRIMARY KEY, type TEXT NOT NULL, '
        .'payload TEXT NOT NULL, created_at TEXT NOT NULL)'
    );
    $insert = $pg->prepare(
        'INSERT INTO events (id, type, payload, created_at) VALUES (:id, :type, :payload, :created_at)'
    );
    foreach (['first', 'second', 'third'] as $i => $label) {
        $insert->execute([
            'id' => 'id-'.$i,
            'type' => 'note',
            'payload' => json_encode(['label' => $label]),
            'created_at' => gmdate('c'),
        ]);
    }

    // Constructing EventLog runs the additive migration.
    $log = new EventLog($pg);
    $log->append('note', ['label' => 'fourth']);

    // Drop the lazily-written session_start, which has no label and is not part of the ordering
    // being asserted — it is a new row, not one of the pre-migration rows.
    $notes = array_values(array_filter($log->all(), fn ($e) => $e['type'] === 'note'));
    $labels = array_map(fn ($e) => $e['payload']['label'], $notes);

    // Backfill order is load-bearing: a wrong order would restate historical cost, so this
    // asserts the OLD rows kept their sequence rather than merely that they survived.
    expect($labels)->toBe(['first', 'second', 'third', 'fourth']);
});

it('refuses a malformed postgres URL with a clear message and no password echo', function () {
    expect(fn () => new Database)
        ->not->toThrow(RuntimeException::class, 'sentinel'); // sanity: no throw on construction

    putenv('PAIDER_DATABASE_URL=postgres://user:hunter2@');

    try {
        Database::connect('/tmp/paider-should-not-exist/paider.db');
        $this->fail('expected a RuntimeException for a database URL with no host or dbname');
    } catch (RuntimeException $e) {
        expect($e->getMessage())->toContain('postgres')
            // A connection error must never print the password back into a terminal or a log.
            ->and($e->getMessage())->not->toContain('hunter2');
    } finally {
        putenv('PAIDER_DATABASE_URL');
    }
});

it('does not silently fall back to SQLite when postgres is unreachable', function () {
    // Port 1 is reserved and never listening. The failure must be a connection error, NOT a
    // quiet success on a local file — silently degrading would mean "your history is in a file
    // you forgot about", which is strictly worse than a hard error.
    putenv('PAIDER_DATABASE_URL=postgres://postgres:postgres@127.0.0.1:1/paider');

    expect(fn () => Database::connect('/tmp/paider-should-not-exist/paider.db'))
        ->toThrow(PDOException::class);

    expect(is_file('/tmp/paider-should-not-exist/paider.db'))->toBeFalse();

    putenv('PAIDER_DATABASE_URL');
});
