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
            'payload' => json_encode($payload),
            'created_at' => gmdate('c'),
        ]);

        return $id;
    }

    /** Every event, in insertion order. */
    public function all(): array
    {
        $stmt = $this->pdo->query('SELECT id, type, payload, created_at FROM events ORDER BY rowid ASC');

        $events = [];

        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $events[] = [
                'id' => $row['id'],
                'type' => $row['type'],
                'payload' => json_decode($row['payload'], true),
                'created_at' => $row['created_at'],
            ];
        }

        return $events;
    }
}
