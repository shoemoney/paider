<?php

/**
 * RAG over the real event log, on real pgvector, with a deterministic fake embedder.
 *
 * A FAKE embedder, deliberately, and this is the crux: the thing under test is the store —
 * chunking, ordering, index lifecycle, and the cost event — not OpenRouter's HTTP. An integration
 * test that only passes when a paid API is reachable is a test that stops running the week
 * someone's key expires, which is how a store quietly rots. The wire format is covered
 * separately in OpenRouterEmbeddingClientTest with a mocked transport.
 *
 * The fake embeds by hashing words into buckets, which gives a metric that is deterministic,
 * comparable, and genuinely responsive to content — so a search for a phrase that appears in one
 * chunk and not another really does rank that chunk first. A test that asserted against a random
 * embedder would pass whatever the code did.
 */

use App\Providers\Contracts\EmbeddingClient;
use App\Storage\CostLedger;
use App\Storage\Database;
use App\Storage\EventLog;
use App\Storage\RagStore;

/** Deterministic bag-of-words embedder. Same input always gives the same vector. */
final class FakeEmbedder implements EmbeddingClient
{
    public int $tokenCount = 0;

    public function __construct(private readonly int $dimensions = 64) {}

    public function embed(array $inputs): array
    {
        $vectors = [];

        foreach ($inputs as $input) {
            $vector = array_fill(0, $this->dimensions, 0.0);
            $this->tokenCount += max(1, mb_strlen($input) / 4);

            foreach (preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($input), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $word) {
                $bucket = crc32($word) % $this->dimensions;
                $vector[$bucket] += 1.0;
            }

            $norm = sqrt(array_sum(array_map(static fn ($v) => $v * $v, $vector)));
            $vectors[] = $norm > 0
                ? array_map(static fn ($v) => $v / $norm, $vector)
                : $vector;
        }

        return $vectors;
    }

    public function model(): string
    {
        return 'fake/embedder-1';
    }

    public function lastTokenCount(): int
    {
        return (int) $this->tokenCount;
    }
}

function ragPdo(?string &$schema): ?PDO
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

    $schema = 'rag_'.bin2hex(random_bytes(6));
    $pdo->exec("CREATE SCHEMA {$schema}");
    $pdo->exec("SET search_path TO {$schema}");

    return $pdo;
}

function requireRagPdo(): PDO
{
    $pdo = $GLOBALS['__rag_pg'] ?? null;

    if (! $pdo instanceof PDO) {
        test()->markTestSkipped('No PAIDER_TEST_PG_URL set — skipping the RAG suite.');
    }

    return $pdo;
}

beforeEach(function () {
    $this->ragSchema = null;
    $GLOBALS['__rag_pg'] = $pdo = ragPdo($this->ragSchema);
});

afterEach(function () {
    $pdo = $GLOBALS['__rag_pg'] ?? null;

    if ($pdo instanceof PDO && is_string($this->ragSchema)) {
        $pdo->exec("DROP SCHEMA IF EXISTS {$this->ragSchema} CASCADE");
    }

    $GLOBALS['__rag_pg'] = null;
    putenv('PAIDER_DATABASE_URL');
});

it('chunks text with overlap so a boundary-straddling fact survives', function () {
    $long = str_repeat('The deploy target is the pi swarm. ', 60);
    $pieces = RagStore::chunk($long);

    expect($pieces)->not->toBe([])
        // More than one chunk, or the overlap does nothing observable.
        ->and(count($pieces))->toBeGreaterThan(1);

    // The claim the overlap exists to serve: at least one chunk carries the whole sentence
    // rather than the sentence being split across a boundary and lost from both halves.
    $whole = array_filter($pieces, static fn (string $p) => str_contains($p, 'The deploy target is the pi swarm.'));
    expect($whole)->not->toBeEmpty();
});

it('returns short text as a single chunk and empty text as none', function () {
    expect(RagStore::chunk('short enough'))->toBe(['short enough'])
        ->and(RagStore::chunk('   '))->toBe([]);
});

it('indexes the event log and retrieves the relevant chunk', function () {
    $pg = requireRagPdo();
    $log = new EventLog($pg);
    $log->append('tier_call', ['tier' => 'coder', 'model' => 'test/model', 'cost_usd' => 0.01]);
    $log->append('memory_set', ['key' => 'deploy target', 'value' => 'the raspberry pi swarm']);

    $embedder = new FakeEmbedder;
    $store = new RagStore($log, $pg);
    $result = $store->index($embedder);

    // 2 appends + the lazily-written session_start = 3 indexed events.
    expect($result['events'])->toBe(3)
        ->and($result['chunks'])->toBeGreaterThan(0);

    $hits = $store->search('deploy target', $embedder, 3);

    expect($hits)->not->toBeEmpty()
        // The retrieval must actually retrieve: the memory_set event is the one that mentions
        // the deploy target, so a store returning anything else is returning noise confidently.
        ->and($hits[0]['event_type'])->toBe('memory_set')
        ->and($hits[0]['content'])->toContain('deploy target')
        ->and($hits[0]['distance'])->toBeLessThan(1.0);
});

it('is idempotent — re-indexing skips what is already indexed', function () {
    $pg = requireRagPdo();
    $log = new EventLog($pg);
    $log->append('memory_set', ['key' => 'k', 'value' => 'v']);

    $store = new RagStore($log, $pg);
    $first = $store->index(new FakeEmbedder);
    $second = $store->index(new FakeEmbedder);

    // Without this, a re-index would duplicate every chunk and quietly double the search space.
    // 1 append + session_start.
    expect($first['events'])->toBe(2)
        ->and($second['events'])->toBe(0)
        ->and($second['skipped'])->toBeGreaterThan(0);

    $count = $pg->query('SELECT COUNT(*) FROM rag_chunks')->fetchColumn();
    expect((int) $count)->toBe($first['chunks']);
    expect((int) $count)->toBeGreaterThan(0);
});

it('books embedding calls into the same event log the cost ledger reads', function () {
    $pg = requireRagPdo();
    $log = new EventLog($pg);
    $log->append('memory_set', ['key' => 'k', 'value' => 'v']);

    $store = new RagStore($log, $pg);
    $store->index(new FakeEmbedder);
    $store->search('k', new FakeEmbedder);

    // THE point of routing through events rather than a side table: retrieval and chat spend
    // are reconciled by one code path. If this is a separate ledger entry, the flagship claim
    // "the ledger reconciles against provider usage" quietly stops being true.
    $types = array_map(fn ($e) => $e['type'], $log->all());
    expect(array_count_values($types)['embedding_call'] ?? 0)->toBe(2)
        ->and(CostLedger::class)->toBeString();
});

it('an unpriced embedding model surfaces as unpriced, never as a free call', function () {
    $pg = requireRagPdo();
    $log = new EventLog($pg);
    $log->append('memory_set', ['key' => 'k', 'value' => 'v']);

    // FakeEmbedder reports a model with no config/prices.php entry.
    (new RagStore($log, $pg))->index(new FakeEmbedder);

    $summary = (new CostLedger($log))->summary();
    $unpriced = 0;
    foreach ($summary as $key => $row) {
        if ($key !== 'session' && is_array($row)) {
            $unpriced += (int) ($row['unpriced_calls'] ?? 0);
        }
    }

    // A confident $0.00 here is the lie the ledger exists to prevent: it would report total
    // spend that is really "we do not know what this cost".
    expect($unpriced)->toBeGreaterThan(0);
});

it('returns nothing for an empty query rather than matching everything', function () {
    $pg = requireRagPdo();
    $log = new EventLog($pg);
    $log->append('memory_set', ['key' => 'k', 'value' => 'v']);
    $store = new RagStore($log, $pg);
    $store->index(new FakeEmbedder);

    // An empty query that returned the top-k would look like a working search and be useless.
    expect($store->search('   ', new FakeEmbedder))->toBe([]);
});

it('formats a pgvector literal without scientific notation', function () {
    $literal = RagStore::toVectorLiteral([1.0, 0.00000001, -0.5, 0.25]);
    expect($literal)->toBe('[1,0.00000001,-0.5,0.25]')
        ->and($literal)->not->toContain('E');
});
