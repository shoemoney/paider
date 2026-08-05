<?php

use App\Providers\AnthropicClient;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;

function makeAnthropicHttp(array $responses, array &$history = []): Client
{
    $mock = new MockHandler($responses);
    $stack = HandlerStack::create($mock);
    $stack->push(Middleware::history($history));

    return new Client(['handler' => $stack]);
}

it('parses a mocked 200 into a ProviderResponse and pulls system out into its own field', function () {
    $history = [];
    $http = makeAnthropicHttp([
        new Response(200, [], json_encode([
            'content' => [
                ['type' => 'text', 'text' => 'Hello '],
                ['type' => 'text', 'text' => 'world'],
            ],
            'usage' => ['input_tokens' => 12, 'output_tokens' => 7],
        ])),
    ], $history);

    $client = new AnthropicClient('sk-ant-test', 'https://api.anthropic.com', $http);

    $response = $client->send([
        ['role' => 'system', 'content' => 'You are helpful.'],
        ['role' => 'user', 'content' => 'Hi'],
    ], 'claude-x');

    expect($response->content)->toBe('Hello world');
    expect($response->tokensIn)->toBe(12);
    expect($response->tokensOut)->toBe(7);

    $request = $history[0]['request'];
    expect($request->getUri()->__toString())->toBe('https://api.anthropic.com/v1/messages');
    expect($request->getHeaderLine('x-api-key'))->toBe('sk-ant-test');
    expect($request->getHeaderLine('anthropic-version'))->toBe('2023-06-01');

    $body = json_decode((string) $request->getBody(), true);
    expect($body['system'])->toBe('You are helpful.');
    expect($body['messages'])->toBe([['role' => 'user', 'content' => 'Hi']]);
    expect($body['model'])->toBe('claude-x');
});

it('omits system field when there are no system messages', function () {
    $history = [];
    $http = makeAnthropicHttp([
        new Response(200, [], json_encode([
            'content' => [['type' => 'text', 'text' => 'ok']],
            'usage' => ['input_tokens' => 1, 'output_tokens' => 1],
        ])),
    ], $history);

    $client = new AnthropicClient('sk-ant-test', 'https://api.anthropic.com', $http);
    $client->send([['role' => 'user', 'content' => 'Hi']], 'claude-x');

    $body = json_decode((string) $history[0]['request']->getBody(), true);
    expect($body)->not->toHaveKey('system');
});

it('parses the served model id off a response body whose model differs from the one requested', function () {
    $http = makeAnthropicHttp([
        new Response(200, [], json_encode([
            'model' => 'claude-opus-5-20260315',
            'content' => [['type' => 'text', 'text' => 'ok']],
            'usage' => ['input_tokens' => 1, 'output_tokens' => 1],
        ])),
    ]);

    $client = new AnthropicClient('sk-ant-test', 'https://api.anthropic.com', $http);
    $response = $client->send([['role' => 'user', 'content' => 'Hi']], 'claude-x');

    expect($response->servedModel)->toBe('claude-opus-5-20260315');
});

it('reports servedModel as null when the response body carries no model field', function () {
    $http = makeAnthropicHttp([
        new Response(200, [], json_encode([
            'content' => [['type' => 'text', 'text' => 'ok']],
            'usage' => ['input_tokens' => 1, 'output_tokens' => 1],
        ])),
    ]);

    $client = new AnthropicClient('sk-ant-test', 'https://api.anthropic.com', $http);
    $response = $client->send([['role' => 'user', 'content' => 'Hi']], 'claude-x');

    expect($response->servedModel)->toBeNull();
});

it('throws when no API key is available at send time', function () {
    $http = makeAnthropicHttp([]);
    $client = new AnthropicClient('', 'https://api.anthropic.com', $http);

    $client->send([['role' => 'user', 'content' => 'Hi']], 'claude-x');
})->throws(RuntimeException::class);

it('forwards $options, allows max_tokens through, and pins model, messages and system', function () {
    $history = [];
    $http = makeAnthropicHttp([
        new Response(200, [], json_encode([
            'content' => [['type' => 'text', 'text' => 'ok']],
            'usage' => ['input_tokens' => 1, 'output_tokens' => 1],
        ])),
    ], $history);

    $client = new AnthropicClient('k', 'https://api.example.com', $http);

    $client->send(
        [['role' => 'system', 'content' => 'real system'], ['role' => 'user', 'content' => 'real']],
        'real-model',
        ['temperature' => 0.2, 'max_tokens' => 128, 'model' => 'impostor', 'system' => 'injected'],
    );

    $body = json_decode((string) $history[0]['request']->getBody(), true);

    expect($body['temperature'])->toBe(0.2);
    expect($body['max_tokens'])->toBe(128);          // a default, so it reads through
    expect($body['model'])->toBe('real-model');      // an invariant, so it does not
    expect($body['system'])->toBe('real system');
});

it('reads both cache buckets and does NOT subtract them from input_tokens', function () {
    // Anthropic reports input_tokens already excluding the cache buckets. Subtracting
    // here -- as the OpenAI-compatible path must -- would under-report the bill.
    // Numbers are a real turn from a measured household transcript.
    $http = makeAnthropicHttp([
        new Response(200, [], json_encode([
            'content' => [['type' => 'text', 'text' => 'ok']],
            'usage' => [
                'input_tokens' => 403,
                'output_tokens' => 515,
                'cache_creation_input_tokens' => 1_669,
                'cache_read_input_tokens' => 172_911,
            ],
        ])),
    ]);

    $r = (new AnthropicClient('sk-ant-test', 'https://api.anthropic.com', $http))
        ->send([['role' => 'user', 'content' => 'hi']], 'claude-opus-5');

    expect($r->tokensIn)->toBe(403)
        ->and($r->cacheWrite)->toBe(1_669)
        ->and($r->cacheRead)->toBe(172_911);
});

it('defaults both cache buckets to zero when the usage block omits them', function () {
    $http = makeAnthropicHttp([
        new Response(200, [], json_encode([
            'content' => [['type' => 'text', 'text' => 'ok']],
            'usage' => ['input_tokens' => 10, 'output_tokens' => 4],
        ])),
    ]);

    $r = (new AnthropicClient('sk-ant-test', 'https://api.anthropic.com', $http))
        ->send([['role' => 'user', 'content' => 'hi']], 'claude-opus-5');

    expect($r->cacheWrite)->toBe(0)->and($r->cacheRead)->toBe(0);
});
