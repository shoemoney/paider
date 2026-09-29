<?php

use App\Providers\OpenAiEmbeddingClient;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;

/*
 * Wire-format tests for the embedding client, against a MOCKED transport.
 *
 * The store's behaviour is covered against real pgvector in RagStoreTest with a deterministic
 * fake embedder. This file covers the part that would otherwise need a paid API key to exercise:
 * the request shape, the response parsing, and above all the two ways an embedding response can
 * be WRONG while looking perfectly healthy — returned out of order, and missing an entry.
 */

function embedClient(array $responses, ?array &$history = null): OpenAiEmbeddingClient
{
    $stack = HandlerStack::create(new MockHandler($responses));

    if ($history !== null) {
        $stack->push(Middleware::history($history));
    }

    return new OpenAiEmbeddingClient(new Client(['handler' => $stack]), apiKey: 'test-key');
}

it('embeds a batch and reports the token count for the ledger', function () {
    $body = [
        'data' => [
            ['index' => 0, 'embedding' => [0.1, 0.2]],
            ['index' => 1, 'embedding' => [0.3, 0.4]],
        ],
        'usage' => ['total_tokens' => 42],
    ];

    $client = embedClient([new Response(200, [], json_encode($body))]);
    $vectors = $client->embed(['first', 'second']);

    expect($vectors)->toBe([[0.1, 0.2], [0.3, 0.4]])
        ->and($client->lastTokenCount())->toBe(42)
        ->and($client->model())->toBeString();
});

it('reorders an out-of-order batch by index rather than by position', function () {
    // A provider returning a batch shuffled is a real failure mode. Trusting array position
    // would attach the wrong vector to the wrong chunk — a wrong RAG answer that looks healthy
    // and cannot be spotted by reading the output.
    $body = ['data' => [
        ['index' => 1, 'embedding' => [0.3, 0.4]],
        ['index' => 0, 'embedding' => [0.1, 0.2]],
    ]];

    $client = embedClient([new Response(200, [], json_encode($body))]);
    expect($client->embed(['first', 'second']))->toBe([[0.1, 0.2], [0.3, 0.4]]);
});

it('refuses a response that omits an input rather than silently returning fewer vectors', function () {
    $body = ['data' => [['index' => 0, 'embedding' => [0.1]]]];

    $client = embedClient([new Response(200, [], json_encode($body))]);
    expect(fn () => $client->embed(['first', 'second']))
        ->toThrow(RuntimeException::class);
});

it('sends the model and an Authorization header', function () {
    $history = [];
    $client = embedClient(
        [new Response(200, [], json_encode(['data' => [['index' => 0, 'embedding' => [0.5]]]]))],
        $history
    );
    $client->embed(['hi']);

    $request = $history[0]['request'];
    expect($request->getHeaderLine('Authorization'))->toBe('Bearer test-key')
        ->and((string) $request->getBody())->toContain('"model"');
});

it('allows a keyless local server, since offline embedding needs no credential', function () {
    $stack = HandlerStack::create(new MockHandler([
        new Response(200, [], json_encode(['data' => [['index' => 0, 'embedding' => [0.5]]]])),
    ]));

    // Ollama / LM Studio speak this shape with no auth. Refusing an empty key would make a free,
    // fully-local embedder impossible to configure — the configuration most worth supporting.
    $client = new OpenAiEmbeddingClient(new Client(['handler' => $stack]), apiKey: '');
    expect($client->embed(['hi']))->toBe([[0.5]]);
});

it('treats an empty batch as a no-op and does not make a request', function () {
    // No MockHandler queued behind this: if a request were made, the test would fail on an empty
    // queue rather than quietly reporting success.
    $client = embedClient([]);
    expect($client->embed([]))->toBe([])
        ->and($client->lastTokenCount())->toBe(0);
});

it('rejects a non-string input instead of coercing it', function () {
    $client = embedClient([]);
    expect(fn () => $client->embed(['ok', 42]))->toThrow(RuntimeException::class);
});

it('rejects an unusable payload rather than returning empty vectors', function () {
    $client = embedClient([new Response(200, [], 'not json')]);
    expect(fn () => $client->embed(['hi']))->toThrow(RuntimeException::class);
});

it('reports zero tokens when the provider omits usage, so the ledger sees it as unpriced', function () {
    // 0 is the load-bearing value: ModelPricing treats an all-zero call as UNKNOWN, so the
    // ledger reports it unpriced rather than booking a confident $0.00.
    $client = embedClient([new Response(200, [], json_encode(['data' => [
        ['index' => 0, 'embedding' => [0.5]],
    ]]))]);

    $client->embed(['hi']);
    expect($client->lastTokenCount())->toBe(0);
});
