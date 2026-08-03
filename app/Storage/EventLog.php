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
    public function __construct(private readonly PDO $pdo)
    {
        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS events ('
            .'id TEXT PRIMARY KEY, '
            .'type TEXT NOT NULL, '
            .'payload TEXT NOT NULL, '
            .'created_at TEXT NOT NULL'
            .')'
        );
    }

    public function append(string $type, array $payload): string
    {
        $id = Uuid::uuid7()->toString();

        $stmt = $this->pdo->prepare(
            'INSERT INTO events (id, type, payload, created_at) VALUES (:id, :type, :payload, :created_at)'
        );

        $stmt->execute([
            'id' => $id,
            'type' => $type,
            // Without JSON_THROW_ON_ERROR an unencodable payload (invalid UTF-8 from file
            // contents or shell output) returns false, binds as '', and writes an event with
            // no payload. An audit log that silently loses what it was auditing is worse than
            // one that fails loudly.
            'payload' => json_encode($payload, JSON_THROW_ON_ERROR),
            'created_at' => gmdate('c'),
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
        $stmt = $this->pdo->query('SELECT id, type, payload, created_at FROM events ORDER BY rowid ASC');

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
