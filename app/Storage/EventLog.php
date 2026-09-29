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
            $count = $this->pdo->query(
                "SELECT COUNT(*) FROM information_schema.columns
                 WHERE table_name = 'events' AND column_name = 'seq'"
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
     * The next insertion sequence number.
     *
     * Read-then-write rather than a sequence object, because a sequence would be a driver-specific
     * schema difference and the whole point of the seam is that nothing above knows which driver it
     * got. MAX(seq)+1 is one cheap indexed read against an append-only table.
     *
     * Two concurrent writers CAN interleave and take the same number. That is survivable and
     * deliberately not solved with a lock: `seq` establishes a total order for reading, and two
     * events sharing a position still both land and both appear in stream(). A tie would only
     * matter if cost were folded per-position, and it is not — the ledger sums values, it does
     * not address events by seq. Serialising every write behind a lock to make a number unique
     * that nothing addresses by would cost more than it buys.
     */
    private function nextSeq(): int
    {
        $max = $this->pdo->query('SELECT COALESCE(MAX(seq), 0) FROM events')->fetchColumn();

        return (int) $max + 1;
    }

    private function insert(string $type, array $payload, ?string $id = null): string
    {
        $id ??= Uuid::uuid7()->toString();

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

        return $id;
    }

    /** Every event, in insertion order. */
    public function all(): array
    {
        return iterator_to_array($this->stream());
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
