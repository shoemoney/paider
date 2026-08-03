<?php

use App\Providers\OpenAiCompatibleClient;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;

function makeOpenAiHttp(array $responses, array &$history = []): Client
{
    $mock = new MockHandler($responses);
    $stack = HandlerStack::create($mock);
    $stack->push(Middleware::history($history));

    return new Client(['handler' => $stack]);
}

it('round-trips a mocked response against a custom base URL with unmodified messages', function () {
    $history = [];
    $http = makeOpenAiHttp([
        new Response(200, [], json_encode([
            'choices' => [
                ['message' => ['content' => 'Hi there']],
            ],
            'usage' => ['prompt_tokens' => 5, 'completion_tokens' => 3],
        ])),
    ], $history);

    $client = new OpenAiCompatibleClient(
        'https://api.example.com/v1',
        'EXAMPLE_API_KEY',
        'test-key-123',
        $http,
    );

    $messages = [
        ['role' => 'system', 'content' => 'You are helpful.'],
        ['role' => 'user', 'content' => 'Hi'],
    ];

    $response = $client->send($messages, 'gpt-x');

    expect($response->content)->toBe('Hi there');
    expect($response->tokensIn)->toBe(5);
    expect($response->tokensOut)->toBe(3);

    $request = $history[0]['request'];
    expect($request->getUri()->__toString())->toBe('https://api.example.com/v1/chat/completions');
    expect($request->getHeaderLine('Authorization'))->toBe('Bearer test-key-123');

    $body = json_decode((string) $request->getBody(), true);
    expect($body['messages'])->toBe($messages);
    expect($body['model'])->toBe('gpt-x');
});

it('reads the key from the given env var when no override is passed', function () {
    putenv('EXAMPLE_API_KEY=from-env');

    $history = [];
    $http = makeOpenAiHttp([
        new Response(200, [], json_encode([
            'choices' => [['message' => ['content' => 'ok']]],
            'usage' => ['prompt_tokens' => 1, 'completion_tokens' => 1],
        ])),
    ], $history);

    $client = new OpenAiCompatibleClient('https://api.example.com/v1', 'EXAMPLE_API_KEY', null, $http);
    $client->send([['role' => 'user', 'content' => 'Hi']], 'gpt-x');

    expect($history[0]['request']->getHeaderLine('Authorization'))->toBe('Bearer from-env');

    putenv('EXAMPLE_API_KEY');
});

it('parses the served model id off an OpenRouter-style response whose model differs from the one requested', function () {
    $http = makeOpenAiHttp([
        new Response(200, [], json_encode([
            'model' => 'anthropic/claude-opus-5-20260315',
            'choices' => [['message' => ['content' => 'ok']]],
            'usage' => ['prompt_tokens' => 1, 'completion_tokens' => 1],
        ])),
    ]);

    $client = new OpenAiCompatibleClient('https://openrouter.ai/api/v1', 'OPENROUTER_API_KEY', 'test-key', $http);
    $response = $client->send([['role' => 'user', 'content' => 'Hi']], 'anthropic/claude-opus-5');

    expect($response->servedModel)->toBe('anthropic/claude-opus-5-20260315');
});

it('reports servedModel as null when the response body carries no model field', function () {
    $http = makeOpenAiHttp([
        new Response(200, [], json_encode([
            'choices' => [['message' => ['content' => 'ok']]],
            'usage' => ['prompt_tokens' => 1, 'completion_tokens' => 1],
        ])),
    ]);

    $client = new OpenAiCompatibleClient('https://api.example.com/v1', 'EXAMPLE_API_KEY', 'test-key', $http);
    $response = $client->send([['role' => 'user', 'content' => 'Hi']], 'gpt-x');

    expect($response->servedModel)->toBeNull();
});

it('throws when no API key is available at send time', function () {
    putenv('EXAMPLE_API_KEY');
    $http = makeOpenAiHttp([]);
    $client = new OpenAiCompatibleClient('https://api.example.com/v1', 'EXAMPLE_API_KEY', null, $http);

    $client->send([['role' => 'user', 'content' => 'Hi']], 'gpt-x');
})->throws(RuntimeException::class);
