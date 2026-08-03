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

it('throws when no API key is available at send time', function () {
    $http = makeAnthropicHttp([]);
    $client = new AnthropicClient('', 'https://api.anthropic.com', $http);

    $client->send([['role' => 'user', 'content' => 'Hi']], 'claude-x');
})->throws(RuntimeException::class);
