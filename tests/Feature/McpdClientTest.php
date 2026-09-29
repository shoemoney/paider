<?php

use App\Providers\McpdClient;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;

it('discovers real tools and sends approved calls as JSON objects', function () {
    $history = [];
    $mock = new MockHandler([
        new Response(200, [], '["time"]'),
        new Response(200, [], '{"tools":[{"name":"now","description":"Time","inputSchema":{"type":"object"}}]}'),
        new Response(200, [], '"noon"'),
    ]);
    $stack = HandlerStack::create($mock);
    $stack->push(Middleware::history($history));
    $tools = (new McpdClient(http: new Client(['handler' => $stack])))->tools();
    expect($tools)->toHaveCount(1);
    expect($tools[0]->execute(['approved' => true])->meta['needs_approval'])->toBeTrue();
    expect($history)->toHaveCount(2);
    expect($tools[0]->execute([], true)->output)->toBe('noon');
    expect((string) $history[2]['request']->getBody())->toBe('{}');
    expect($history[2]['request']->getUri()->getPath())->toBe('api/v1/servers/time/tools/now');
});

it('reports daemon failures instead of registering placeholder tools', function () {
    $client = new McpdClient(http: new Client(['handler' => new MockHandler([new Response(503)])]));
    expect(fn () => $client->tools())->toThrow(RuntimeException::class, 'HTTP 503');
});

it('rejects malformed discovery and redirects', function ($body, $status) {
    $client = new McpdClient(http: new Client(['handler' => new MockHandler([new Response($status, [], $body)])]));
    expect(fn () => $client->tools())->toThrow(Exception::class);
})->with([['{}', 200], ['not json', 200], ['[false]', 200], ['', 302]]);

it('rejects credentials in daemon URLs', function () {
    expect(fn () => new McpdClient('http://secret@localhost:8090'))->toThrow(RuntimeException::class);
});
