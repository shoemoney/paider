<?php

namespace App\Storage;

use App\Providers\Contracts\EmbeddingClient;
use App\Tools\ToolResult;
use PDO;
use RuntimeException;

/**
 * Retrieval over the project event log, on pgvector.
 *
 * Requires Postgres: pgvector has no SQLite equivalent, so unlike every other store in this
 * directory this one is Postgres-only. That is why the DEFAULT stays SQLite — a user who never
 * sets PAIDER_DATABASE_URL simply has no RAG, and loses nothing else.
 *
 * Design notes worth stating, because each is a decision rather than an implementation detail:
 *
 * - Chunks come FROM the event log, not from a separate corpus. There is no second source of
 *   truth to keep in sync, and anything Paider has seen is retrievable.
 * - Retrieval does not read the embedding call's own cost from here; the store appends an
 *   `embedding_call` EVENT and the ledger derives it, so a vector search and a chat turn are
 *   reconciled by exactly one code path.
 * - Cosine distance, not L2. Cosine ignores vector magnitude, which is what you want when
 *   comparing texts of different lengths.
 */
final class RagStore
{
    /** pgvector's hard limit on a vector literal. 2000 dims x ~10 chars is comfortably inside it. */
    private const MAX_DIMENSIONS = 2000;

    /**
     * Chunk size in characters, and the overlap between consecutive chunks.
     *
     * Overlap is not decoration: a fact that straddles a boundary ("the deploy target is the pi
     * swarm") is retrievable from NEITHER half if you split it clean. A quarter of the window
     * overlapping is the usual compromise — enough that a boundary-straddling sentence survives
     * intact in one chunk, small enough not to double the index.
     */
    public const CHUNK_SIZE = 1200;

    public const CHUNK_OVERLAP = 300;

    public function __construct(
        private readonly EventLog $events,
        private readonly PDO $pdo,
    ) {}

    /**
     * Run a callback with the caller's schema first and public second, restoring it afterwards.
     *
     * Postgres resolves type names at PREPARE time against the search_path in force, and the
     * pgvector `vector` type lives in public. Under a private search_path — a tenant schema, a
     * test schema — any statement mentioning `vector` fails with `type "vector" does not
     * exist`, which reads like a missing extension when the extension is installed and healthy.
     *
     * Restored in a finally: leaving the path rewritten would make an unrelated later query in
     * the same process write into public.
     *
     * @template T
     *
     * @param  callable(): T  $work
     * @return T
     */
    private function withVectorSchema(callable $work): mixed
    {
        $previous = (string) $this->pdo->query('SHOW search_path')->fetchColumn();
        $target = trim((string) strtok($previous, ','));

        $this->pdo->exec('SET search_path TO '.($target !== '' ? $target : 'public').', public');

        try {
            return $work();
        } finally {
            $this->pdo->exec("SET search_path TO {$previous}");
        }
    }

    /**
     * Create the vector table. Idempotent, and called by index()/search() rather than in a
     * constructor side effect so that merely loading a project never writes to the database.
     */
    public function ensureSchema(): void
    {
        $this->pdo->exec('CREATE EXTENSION IF NOT EXISTS vector');

        $this->withVectorSchema(function (): void {
            $this->pdo->exec(
                'CREATE TABLE IF NOT EXISTS rag_chunks ('
                .'id TEXT PRIMARY KEY, '
                .'event_id TEXT NOT NULL, '
                .'event_type TEXT NOT NULL, '
                .'chunk_index INTEGER NOT NULL, '
                .'content TEXT NOT NULL, '
                .'model TEXT NOT NULL, '
                .'dimensions INTEGER NOT NULL, '
                .'embedding vector, '
                .'created_at TEXT NOT NULL'
                .')'
            );
        });
    }

    /**
     * Embed and store every event that does not already have a chunk.
     *
     * @return ToolResult-style summary: counts, plus what it cost.
     */
    public function index(?EmbeddingClient $embedder = null, ?string $sessionId = null): array
    {
        $this->ensureSchema();

        if ($embedder === null) {
            throw new RuntimeException('RagStore::index() needs an EmbeddingClient');
        }

        $indexed = 0;
        $chunks = 0;
        $skipped = 0;
        $tokens = 0;

        $seen = $this->indexedEventIds();

        foreach ($this->events->stream() as $event) {
            if (isset($seen[$event['id']])) {
                $skipped++;

                continue;
            }

            $text = $this->textOf($event);

            if ($text === '') {
                $skipped++;

                continue;
            }

            $pieces = self::chunk($text);
            $vectors = $embedder->embed($pieces);

            if (count($vectors) !== count($pieces)) {
                throw new RuntimeException('Embedding count did not match chunk count');
            }

            // Accumulated HERE, per call, not read once after the loop.
            //
            // lastTokenCount() is reset at the entry of every embed() and holds only the MOST
            // RECENT call's usage. Indexing a 200-event log therefore books one event carrying
            // roughly 1/200th of the tokens actually spent — while the same event reported
            // `chunks` as the full total, so the row was internally inconsistent as well as
            // understated. The cost ledger then priced that one small number as if it were the
            // whole job.
            $tokens += $embedder->lastTokenCount();

            $this->storeChunks($event, $pieces, $vectors, $embedder->model());
            $indexed++;
            $chunks += count($pieces);
        }

        // Book the embedding call through the SAME event log every other call uses, so the cost
        // ledger has one reconciliation path rather than a second one that can drift from it.
        //
        // ONLY when something was actually embedded. This was unconditional, and that was a real
        // accounting bug found by an adversarial review: a re-index that finds everything already
        // indexed embeds nothing, so the embedder reports 0 tokens — and ModelPricing's LOCKED
        // rule treats an all-zero call as UNKNOWN, not free. The result was a phantom
        // "unpriced embedding call" in the ledger on every no-op index, inflating the count the
        // ledger reports as unknown-spend. In a tool whose flagship claim is that its ledger
        // reconciles against provider usage, inventing a call that never happened is the worst
        // class of bug available.
        //
        // A real search that embeds a query always books, even when it matches nothing: that
        // call was made and paid for.
        if ($chunks > 0) {
            $this->events->append('embedding_call', [
                'model' => $embedder->model(),
                // The SUM across every embed() in this run, not the last one.
                'tokens_in' => $tokens,
                'tokens_out' => 0,
                'chunks' => $chunks,
            ]);
        }

        return ['events' => $indexed, 'chunks' => $chunks, 'skipped' => $skipped];
    }

    /**
     * Nearest chunks to a query.
     *
     * @return array<int, array{event_id: string, event_type: string, content: string, distance: float}>
     */
    public function search(string $query, EmbeddingClient $embedder, int $limit = 5): array
    {
        $this->ensureSchema();

        $query = trim($query);

        if ($query === '') {
            return [];
        }

        $vectors = $embedder->embed([$query]);
        $vector = $vectors[0] ?? null;

        if (! is_array($vector) || $vector === []) {
            return [];
        }

        $this->events->append('embedding_call', [
            'model' => $embedder->model(),
            'tokens_in' => $embedder->lastTokenCount(),
            'tokens_out' => 0,
            'chunks' => 0,
        ]);

        $dimensions = count($vector);

        if ($dimensions > self::MAX_DIMENSIONS) {
            throw new RuntimeException("Embedding has {$dimensions} dimensions, over pgvector's limit");
        }

        return $this->withVectorSchema(function () use ($vector, $dimensions, $limit): array {
            // `CAST(:query AS vector)` is REQUIRED, not decorative. A bound parameter arrives as
            // untyped text, so Postgres cannot resolve which `<=>` overload to use and fails with
            // `operator does not exist: public.vector <=> unknown`.
            //
            // The cast is resolved at PREPARE time, which is why this runs inside
            // withVectorSchema(): under a caller's private search_path the `vector` type is not
            // visible and the same statement fails with `type "vector" does not exist` — an error
            // that reads like a missing extension when the extension is installed and fine.
            $statement = $this->pdo->prepare(
                'SELECT event_id, event_type, content, embedding <=> CAST(:query AS vector) AS distance
                 FROM rag_chunks
                 WHERE dimensions = :dimensions AND embedding IS NOT NULL
                 ORDER BY embedding <=> CAST(:query AS vector)
                 LIMIT :limit'
            );

            $statement->bindValue(':query', self::toVectorLiteral($vector), PDO::PARAM_STR);
            $statement->bindValue(':dimensions', $dimensions, PDO::PARAM_INT);
            $statement->bindValue(':limit', max(1, min(50, $limit)), PDO::PARAM_INT);
            $statement->execute();

            return array_map(static fn (array $row): array => [
                'event_id' => (string) $row['event_id'],
                'event_type' => (string) $row['event_type'],
                // Trimmed: a retrieved chunk is prompt context, and the first 1500 characters is
                // what a model can use before the retrieval crowds out the actual task.
                'content' => mb_substr((string) $row['content'], 0, 1500),
                'distance' => (float) $row['distance'],
            ], $statement->fetchAll(PDO::FETCH_ASSOC));
        });
    }

    /**
     * Split text on sentence-ish boundaries, with overlap.
     *
     * @return array<int, string>
     */
    public static function chunk(string $text, int $size = self::CHUNK_SIZE, int $overlap = self::CHUNK_OVERLAP): array
    {
        $text = trim($text);

        if ($text === '') {
            return [];
        }

        if (mb_strlen($text) <= $size) {
            return [$text];
        }

        $pieces = [];
        $cursor = 0;
        $length = mb_strlen($text);
        $step = max(1, $size - $overlap);

        while ($cursor < $length) {
            $window = mb_substr($text, $cursor, $size);

            // Prefer a paragraph break, then a sentence end, so a chunk is not cut mid-word when
            // a natural boundary is available nearby. Falls back to a hard cut only when the
            // window has no boundary at all.
            $cut = mb_strrpos($window, "\n\n");
            $boundary = $cut !== false ? $cut : mb_strrpos($window, '. ');

            if ($boundary !== false && $boundary > intdiv($size, 2)) {
                $window = mb_substr($window, 0, $boundary + 1);
            }

            $pieces[] = trim($window);
            $cursor += $step;

            if ($cursor >= $length) {
                break;
            }
        }

        return array_values(array_filter($pieces, static fn (string $p): bool => $p !== ''));
    }

    /** @param array<int, array<int, float>> $vectors */
    private function storeChunks(array $event, array $pieces, array $vectors, string $model): void
    {
        $insert = $this->pdo->prepare(
            'INSERT INTO rag_chunks (id, event_id, event_type, chunk_index, content, model, dimensions, embedding, created_at)
             VALUES (:id, :event_id, :event_type, :chunk_index, :content, :model, :dimensions, :embedding, :created_at)
             ON CONFLICT (id) DO NOTHING'
        );

        foreach ($pieces as $index => $piece) {
            $insert->execute([
                'id' => $event['id'].':'.$index,
                'event_id' => $event['id'],
                'event_type' => $event['type'],
                'chunk_index' => $index,
                'content' => $piece,
                'model' => $model,
                'dimensions' => count($vectors[$index]),
                'embedding' => self::toVectorLiteral($vectors[$index]),
                'created_at' => gmdate('c'),
            ]);
        }
    }

    /** @return array<string, true> */
    private function indexedEventIds(): array
    {
        $seen = [];

        foreach ($this->pdo->query('SELECT DISTINCT event_id FROM rag_chunks')->fetchAll(PDO::FETCH_COLUMN) as $id) {
            $seen[(string) $id] = true;
        }

        return $seen;
    }

    /** The retrievable text of an event — its JSON payload, which is where the content lives. */
    private function textOf(array $event): string
    {
        // An embedding_call is bookkeeping, not knowledge. Indexing it would mean the index
        // retrieves its own cost records, and every re-index would append another one — so the
        // corpus would grow with its own telemetry and the store would never converge.
        if ($event['type'] === CostLedger::EMBEDDING_CALL) {
            return '';
        }

        $payload = $event['payload'];

        if (! is_array($payload)) {
            return '';
        }

        $interesting = array_diff_key($payload, array_flip(['session_id']));

        if ($interesting === []) {
            return '';
        }

        return trim(json_encode($interesting, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '');
    }

    /**
     * pgvector accepts a vector as the string "[1,2,3]".
     *
     * Numbers are formatted with a fixed precision rather than PHP's default repr, which can emit
     * scientific notation ("1.0E-5") for small magnitudes. Postgres parses that fine, but the
     * fixed form is stable across drivers and diffs, and a column value that is byte-identical
     * for identical input is worth the two lines.
     */
    public static function toVectorLiteral(array $vector): string
    {
        return '['.implode(',', array_map(
            static fn (float $v): string => rtrim(rtrim(number_format($v, 8, '.', ''), '0'), '.'),
            $vector
        )).']';
    }
}
