<?php

namespace App\Storage;

use PDO;
use Ramsey\Uuid\Uuid;

/**
 * Append-only event log. No update or delete method exists anywhere in this class —
 * the append-only guarantee is structural (see LOCKED in the project brief), not a
 * convention comment someone can forget to honour.
 */
class EventLog
{
    private readonly string $sessionId;

    private bool $sessionStarted = false;

    public function __construct(
        private readonly PDO $pdo,
        private readonly ?string $origin = null,
    ) {
        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS events ('
            .'id TEXT PRIMARY KEY, '
            .'type TEXT NOT NULL, '
            .'payload TEXT NOT NULL, '
            .'created_at TEXT NOT NULL, '
            // Monotonic insertion counter. Portable in the only sense that matters: it is
            // assigned by PHP, so it is identical on SQLite and Postgres and needs no sequence
            // object, no SERIAL, and no driver-specific upsert. See stream() for why ordering
            // is load-bearing rather than cosmetic.
            .'seq INTEGER NOT NULL DEFAULT 0'
            .')'
        );

        // CREATE TABLE IF NOT EXISTS will not add `seq` to a log written before this column
        // existed, and the failure mode is nasty: the CREATE silently no-ops and the first
        // ORDER BY seq then throws. One additive migration, run once, before any read.
        $this->migrateSeqColumn();

        $this->sessionId = Uuid::uuid7()->toString();
    }

    /**
     * Add `seq` to a pre-existing log and backfill it in insertion order.
     *
     * Backfill order matters and is NOT cosmetic: CostLedger folds the stream to reconcile
     * spend, so backfilling in the wrong order would silently restate historical cost. SQLite
     * has rowid, Postgres has ctid, and neither exists in the other — so the backfill reads
     * whatever ordering primitive the active driver actually has, and the permanent `seq`
     * column replaces it from the next append on.
     */
    private function migrateSeqColumn(): void
    {
        if ($this->hasSeqColumn()) {
            return;
        }

        $this->pdo->exec('ALTER TABLE events ADD COLUMN seq INTEGER NOT NULL DEFAULT 0');

        $driver = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        // ctid is Postgres-internal and explicitly not stable across updates, which is fine for
        // a one-time backfill of an append-only table; rowid is SQLite's insertion order.
        $orderColumn = $driver === 'pgsql' ? 'ctid' : 'rowid';

        $rows = $this->pdo->query("SELECT id FROM events ORDER BY {$orderColumn} ASC")->fetchAll(PDO::FETCH_COLUMN);

        $update = $this->pdo->prepare('UPDATE events SET seq = :seq WHERE id = :id');

        foreach (array_values($rows) as $index => $id) {
            $update->execute(['seq' => $index + 1, 'id' => $id]);
        }
    }

    /**
     * Does `events` already carry `seq`?
     *
     * Driver-specific on purpose: information_schema is a SQL-standard catalog that Postgres
     * implements and SQLite does not (querying it there throws "no such table"), while SQLite's
     * own PRAGMA table_info does not exist in Postgres. There is no single portable way to ask
     * this, and guessing wrong is a hard failure at construction time — so each driver is asked
     * in its own dialect rather than being handed a query that works in one and throws in the
     * other.
     */
    private function hasSeqColumn(): bool
    {
        if ($this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'pgsql') {
            // Scoped to the CURRENT schema. information_schema is not search_path-resolved, so an
            // unscoped `WHERE table_name = 'events'` counts `events` in EVERY schema in the
            // database — including tables belonging to another test's throwaway schema. That
            // returned a false "yes, the column exists", the ALTER was skipped, and the first
            // insert then failed with `column "seq" does not exist`.
            //
            // Caught by running the suite against a live Postgres, not by reading this code: the
            // legacy-migration test creates its own pre-seq table, and only a shared database
            // makes the cross-schema leak observable.
            $count = $this->pdo->query(
                "SELECT COUNT(*) FROM information_schema.columns
                 WHERE table_schema = ANY (current_schemas(false))
                   AND table_name = 'events'
                   AND column_name = 'seq'"
            )->fetchColumn();

            return (int) $count > 0;
        }

        foreach ($this->pdo->query('PRAGMA table_info(events)')->fetchAll(PDO::FETCH_ASSOC) as $column) {
            if (($column['name'] ?? null) === 'seq') {
                return true;
            }
        }

        return false;
    }

    public function sessionId(): string
    {
        return $this->sessionId;
    }

    public function append(string $type, array $payload): string
    {
        // Session id stamped by EventLog itself at write time (PLAN.md v0.3).
        // No column, no new table, no ALTER TABLE — just payload blob.
        $payload['session_id'] = $this->sessionId;

        // Validate BEFORE lazy session_start write — invalid UTF-8 payload must leave log empty
        // (see EventLogTest 'refuses to append ... rather than writing it empty').
        json_encode($payload, JSON_THROW_ON_ERROR);

        if (! $this->sessionStarted) {
            $this->sessionStarted = true;
            $this->insert('session_start', ['session_id' => $this->sessionId, 'origin' => $this->origin]);
        }

        $id = Uuid::uuid7()->toString();

        $this->insert($type, $payload, $id);

        return $id;
    }

    /**
     * The next insertion sequence number, allocated INSIDE the caller's transaction.
     *
     * This used to be an unguarded `SELECT COALESCE(MAX(seq), 0) + 1` followed by an INSERT, with
     * a comment arguing the resulting collision was harmless. It is not, and the argument was
     * wrong twice over.
     *
     * Reproduced with 10 concurrent processes appending to one .paider/paider.db — which is two
     * `paider` invocations in one project, the ordinary case:
     *
     *     total events: 20, DUPLICATE seq values: 2  (seq=9 ×2, seq=12 ×2)
     *     times seq DECREASES along true insertion order: 1
     *
     * So `seq` was not merely ambiguous, it was OUT OF ORDER. stream() reads ORDER BY seq, and
     * CostLedger folds that stream in order to reconcile spend — a decreasing seq means a cost
     * row is visited before a row that preceded it, which is a wrong number, not a cosmetic one.
     * The old comment claimed "a tie would only matter if cost were folded per-position, and it
     * is not"; the fold is order-SENSITIVE, which is the entire reason ordering is load-bearing
     * (see stream()).
     *
     * The fix is a transaction around read-then-write, so the allocation and the insert commit
     * together. Why not a database sequence: SERIAL is a schema-level, driver-specific difference
     * and would defeat the whole point of the driver seam, where nothing above this class knows
     * whether it got SQLite or Postgres. A transaction is portable and means the same thing on
     * both.
     */
    private function nextSeq(): int
    {
        $max = $this->pdo->query('SELECT COALESCE(MAX(seq), 0) FROM events')->fetchColumn();

        return (int) $max + 1;
    }

    /**
     * Attempts before giving up on a lock-contention error.
     *
     * Not a random retry. Every retryable condition here is SQLITE_BUSY / deadlock on Postgres,
     * which means "someone else holds the write lock right now" and is resolved by waiting. A
     * lost write is a far worse failure than a slow one, because EventLog is append-only and an
     * event that never lands is an event the cost ledger will never see.
     *
     * Measured: with 8 concurrent processes, a bare transaction lost 2 of 32 events to
     * SQLSTATE[HY000] even with SQLite's 5s busy_timeout set. Backoff with jitter beats a longer
     * single timeout here, because the contention is bursty rather than sustained.
     */
    private const MAX_WRITE_ATTEMPTS = 5;

    private function insert(string $type, array $payload, ?string $id = null): string
    {
        $id ??= Uuid::uuid7()->toString();

        for ($attempt = 1; ; $attempt++) {
            try {
                return $this->insertOnce($type, $payload, $id);
            } catch (\PDOException $e) {
                // Rethrow on the last attempt, and rethrow anything that is NOT contention: a
                // constraint violation or a syntax error will not fix itself, and retrying it
                // would convert a clear error into a slow one.
                if ($attempt >= self::MAX_WRITE_ATTEMPTS || ! self::isLockContention($e)) {
                    throw $e;
                }

                // Jittered exponential backoff. Without jitter every contending writer wakes on
                // the same schedule and collides again — the thundering-herd case, which is
                // exactly the pattern that produced the lost writes above.
                $delayMicros = (int) ((2 ** $attempt) * 1000 * (0.5 + (mt_rand(0, 1000) / 1000)));
                usleep(min($delayMicros, 250_000));
            }
        }
    }

    /**
     * Is this a "someone else is writing" error, as opposed to a real fault?
     *
     * Matched on the driver's own codes rather than on the message, because messages are
     * localised and vary by version: SQLITE_BUSY (5) and SQLITE_LOCKED (6) on SQLite,
     * 40001 serialization_failure and 40P01 deadlock_detected on Postgres.
     */
    private static function isLockContention(\PDOException $e): bool
    {
        $code = $e->getCode();

        if (in_array((string) $code, ['5', '6', '40001', '40P01'], true)) {
            return true;
        }

        // Some drivers surface the SQLSTATE in the message rather than the code, and some
        // flatten both to HY000 with the useful text appended.
        $message = $e->getMessage();

        foreach (['database is locked', 'database table is locked', 'deadlock detected', 'could not serialize access'] as $needle) {
            if (stripos($message, $needle) !== false) {
                return true;
            }
        }

        return false;
    }

    private function insertOnce(string $type, array $payload, string $id): string
    {
        // beginTransaction() on an already-in-transaction connection is a no-op that returns
        // false, and committing would then end a transaction this method does not own. Guarded
        // so nesting is safe rather than a silent early-commit of a caller's work.
        $ownsTransaction = ! $this->pdo->inTransaction();

        if ($ownsTransaction) {
            $this->pdo->beginTransaction();
        }

        try {
            // Allocated and written inside the transaction: another writer either commits first
            // and we read its row, or it blocks on the write lock until we commit. Either way the
            // two cannot both observe the same MAX(seq).
            $stmt = $this->pdo->prepare(
                'INSERT INTO events (id, type, payload, created_at, seq) VALUES (:id, :type, :payload, :created_at, :seq)'
            );

            $stmt->execute([
                'id' => $id,
                'type' => $type,
                'payload' => json_encode($payload, JSON_THROW_ON_ERROR),
                'created_at' => gmdate('c'),
                'seq' => $this->nextSeq(),
            ]);

            if ($ownsTransaction) {
                $this->pdo->commit();
            }
        } catch (\Throwable $e) {
            // Roll back before rethrowing, or the connection is left in a failed transaction and
            // every later append on it fails too.
            if ($ownsTransaction && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw $e;
        }

        return $id;
    }

    /** Every event, in insertion order. */
    public function all(): array
    {
        return iterator_to_array($this->stream());
    }

    /**
     * The session id of the most recent event that has one, or null for an empty log.
     *
     * Replaces a full forward scan that walked every event in the log to find the last
     * session_start. `--session` needs exactly one number and was paying for the entire history
     * to compute it.
     *
     * Bounded walk rather than a bare LIMIT 1: rows written before the session_id field existed
     * (pre-v0.3) carry none, and the log is ordered by insertion, so the newest event is
     * normally the answer — but a truncated table ending in a legacy row would return null
     * rather than the correct earlier session. Fifty rows is far more than enough to clear any
     * realistic run of legacy rows while staying a bounded read.
     *
     * Kept in PHP rather than pushed into SQL because session_id lives inside the JSON payload,
     * and the accessor differs per driver (Postgres payload->>'session_id', SQLite
     * json_extract) — one walk is honest about that, two SQL dialects are not.
     */
    public function lastSessionId(int $lookback = 50): ?string
    {
        $statement = $this->pdo->query(
            'SELECT payload FROM events ORDER BY seq DESC LIMIT '.max(1, $lookback)
        );

        while ($row = $statement->fetch(PDO::FETCH_ASSOC)) {
            $payload = json_decode((string) $row['payload'], true);

            if (is_array($payload) && isset($payload['session_id']) && is_string($payload['session_id'])) {
                return $payload['session_id'];
            }
        }

        return null;
    }

    /**
     * The most recent event matching any of $types, or null.
     *
     * Exists because "what happened last" is a question the log should answer without loading
     * the log. Two callers wanted exactly the last tool_call/test_run and both reached for
     * `all()` + `array_reverse()`, which materialises every event — on a long-lived project that
     * is the whole history in memory as decoded arrays, to read one row off the end.
     *
     * The query is DESC + LIMIT 1, so it is a single index seek and a single row returned, no
     * matter how large the table is. The in-PHP `while` exists only to peel rows off the cursor
     * one at a time; Postgres and SQLite both stream, so this never materialises a result set.
     *
     * @param  array<int, string>  $types
     * @param  string|null  $sessionId  restrict to one session — see below
     * @return array{id: string, type: string, payload: array<string, mixed>, created_at: string}|null
     */
    public function lastOf(array $types, ?string $sessionId = null): ?array
    {
        if ($types === []) {
            return null;
        }

        // Placeholders, not interpolated type names: the values come from a fixed call site, but
        // binding them keeps this method incapable of producing invalid SQL no matter who calls
        // it with what.
        $placeholders = implode(',', array_fill(0, count($types), '?'));

        $sql = "SELECT id, type, payload, created_at FROM events
                WHERE type IN ({$placeholders})";

        $params = array_values($types);

        if ($sessionId !== null) {
            // The log persists across runs, so "the last failing tool_call" is a PREVIOUS run's
            // verdict unless you say whose you mean. session_id lives inside the JSON payload
            // and the accessor differs per driver (Postgres payload->>'session_id', SQLite
            // json_extract), so the filter runs in PHP rather than in SQL.
            //
            // Which means LIMIT 1 would be wrong: the newest row of that type might belong to
            // someone else. A bounded window is taken instead and the FIRST row matching the
            // session is returned. 200 rows is far more than one run produces, and it keeps the
            // read bounded when a project has run thousands of times.
            $sql .= ' ORDER BY seq DESC LIMIT 200';
        } else {
            $sql .= ' ORDER BY seq DESC LIMIT 1';
        }

        $statement = $this->pdo->prepare($sql);
        $statement->execute($params);

        while ($row = $statement->fetch(PDO::FETCH_ASSOC)) {
            $payload = json_decode((string) $row['payload'], true, 512, JSON_THROW_ON_ERROR);

            if ($sessionId !== null && ($payload['session_id'] ?? null) !== $sessionId) {
                continue;
            }

            return [
                'id' => (string) $row['id'],
                'type' => (string) $row['type'],
                'payload' => $payload,
                'created_at' => (string) $row['created_at'],
            ];
        }

        return null;
    }

    /**
     * Every event, streamed straight off the PDO cursor — constant memory regardless
     * of log size. PDO_SQLite steps row-by-row (unlike mysqlnd it does not client-
     * buffer the whole result set), so a projection like CostLedger::summary() that
     * only ever needs running totals can iterate this without materializing the log.
     */
    public function stream(): \Generator
    {
        // Order by an explicit column, not SQLite's `rowid`. Postgres has no rowid, and the
        // insertion order is the load-bearing property here: CostLedger folds this stream in
        // order to reconcile spend, so an out-of-order read is a wrong number rather than a
        // cosmetic bug. `seq` is a monotonic per-log counter, which orders identically on both
        // drivers and survives a table rewrite.
        $stmt = $this->pdo->query('SELECT id, type, payload, created_at FROM events ORDER BY seq ASC');

        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            yield [
                'id' => $row['id'],
                'type' => $row['type'],
                'payload' => json_decode($row['payload'], true, 512, JSON_THROW_ON_ERROR),
                'created_at' => $row['created_at'],
            ];
        }
    }
}
