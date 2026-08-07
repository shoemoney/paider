<?php

use App\Storage\ProjectEnv;
use App\Support\UrlGuard;
use App\Tools\FetchUrlTool;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;

/**
 * $history is taken BY REFERENCE, not returned. Guzzle's history middleware binds to the
 * container variable itself, and returning it inside an array copies it at return time — the
 * caller then sees a permanently empty array, which silently turns every "no request was made"
 * assertion into one that cannot fail.
 *
 * @param  array<int, Response>  $responses
 * @param  array<int, array>  $history
 */
function fetchToolWith(array $responses, ?array &$history = null): FetchUrlTool
{
    $history = [];
    $stack = HandlerStack::create(new MockHandler($responses));
    $stack->push(Middleware::history($history));

    return new FetchUrlTool(new Client(['handler' => $stack]));
}

function allow(string $url): array
{
    return ['url' => $url, 'approval' => 'allow-once'];
}

test('a fetch with no approval decision asks for one instead of making a request', function () {
    $tool = fetchToolWith([new Response(200, [], 'should never be sent')], $history);

    $result = $tool->execute(['url' => 'https://example.com']);

    expect($result->ok)->toBeFalse();
    expect($result->meta['needs_approval'])->toBeTrue();
    expect($history)->toBeEmpty();
});

test('a denied fetch makes no request', function () {
    $tool = fetchToolWith([new Response(200, [], 'should never be sent')], $history);

    expect($tool->execute(['url' => 'https://example.com', 'approval' => 'deny'])->ok)->toBeFalse();
    expect($history)->toBeEmpty();
});

test('an approved fetch of a public page returns its text', function () {
    $tool = fetchToolWith([new Response(200, ['Content-Type' => 'text/plain'], 'hello from the internet')]);

    $result = $tool->execute(allow('https://example.com'));

    expect($result->ok)->toBeTrue();
    expect($result->output)->toBe('hello from the internet');
});

test('html is reduced to text and script bodies are dropped rather than read as prose', function () {
    $html = '<html><head><style>body{color:red}</style><script>var token="leak";</script></head>'
        .'<body><h1>Title</h1><p>Body text.</p></body></html>';

    $tool = fetchToolWith([new Response(200, ['Content-Type' => 'text/html; charset=utf-8'], $html)]);

    $result = $tool->execute(allow('https://example.com'));

    expect($result->output)->toContain('Title')->toContain('Body text.');
    expect($result->output)->not->toContain('leak')->not->toContain('color:red');
});

test('a private, loopback or link-local target is refused without any request being made', function (string $url) {
    $tool = fetchToolWith([new Response(200, [], 'should never be sent')], $history);

    $result = $tool->execute(allow($url));

    expect($result->ok)->toBeFalse();
    expect($result->output)->toContain('refused');
    expect($history)->toBeEmpty();
})->with([
    'loopback' => 'http://127.0.0.1/',
    'rfc1918 nas' => 'http://192.168.1.10/',
    'rfc1918 /8' => 'http://10.0.0.5/',
    'link-local metadata' => 'http://169.254.169.254/latest/meta-data/',
    'tailscale cgnat' => 'http://100.101.102.103/',
    'ipv6 loopback' => 'http://[::1]/',
    'ipv4-mapped ipv6' => 'http://[::ffff:192.168.1.10]/',
]);

test('a non-http scheme is refused', function (string $url) {
    $tool = fetchToolWith([new Response(200, [], 'nope')], $history);

    expect($tool->execute(allow($url))->ok)->toBeFalse();
    expect($history)->toBeEmpty();
})->with([
    'file' => 'file:///etc/passwd',
    'gopher' => 'gopher://example.com/',
    'ftp' => 'ftp://example.com/',
]);

test('credentials embedded in the URL are refused', function () {
    $tool = fetchToolWith([new Response(200, [], 'nope')], $history);

    expect($tool->execute(allow('https://user:secret@example.com/'))->ok)->toBeFalse();
    expect($history)->toBeEmpty();
});

test('a redirect from a public page onto the LAN is refused at the hop, not followed', function () {
    // The bypass this whole design exists for: the first URL passes the guard cleanly, and the
    // server it reaches answers with a redirect to a private address. Client-side redirect
    // following would take it; following by hand re-runs the guard and stops.
    $tool = fetchToolWith([
        new Response(302, ['Location' => 'http://192.168.1.10/admin']),
        new Response(200, [], 'INTERNAL DATA'),
    ], $history);

    $result = $tool->execute(allow('https://example.com/start'));

    expect($result->ok)->toBeFalse();
    expect($result->output)->toContain('refused');
    expect($result->output)->toContain('192.168.1.10');
    // Exactly one request: the public one. The second response was never reached.
    expect($history)->toHaveCount(1);
});

test('a relative redirect resolves against the current url and is re-checked', function () {
    $tool = fetchToolWith([
        new Response(302, ['Location' => '/second']),
        new Response(200, ['Content-Type' => 'text/plain'], 'arrived'),
    ], $history);

    $result = $tool->execute(allow('https://example.com/first'));

    expect($result->ok)->toBeTrue();
    expect($result->output)->toBe('arrived');
    expect((string) $history[1]['request']->getUri())->toBe('https://example.com/second');
});

test('a redirect chain longer than the cap stops instead of looping forever', function () {
    $tool = fetchToolWith(array_fill(0, 10, new Response(302, ['Location' => 'https://example.com/next'])));

    $result = $tool->execute(allow('https://example.com/start'));

    expect($result->ok)->toBeFalse();
    expect($result->output)->toContain('refused');
});

test('a body larger than the cap is truncated rather than read whole', function () {
    $tool = fetchToolWith([new Response(200, ['Content-Type' => 'text/plain'], str_repeat('x', 300_000))]);

    $result = $tool->execute(allow('https://example.com'));

    expect($result->meta['truncated'])->toBeTrue();
    expect(strlen($result->output))->toBeLessThan(300_000);
});

test('the guard reports public addresses as public', function () {
    expect(UrlGuard::isPublic('93.184.216.34'))->toBeTrue();
    expect(UrlGuard::isPublic('8.8.8.8'))->toBeTrue();
    expect(UrlGuard::isPublic('2606:2800:220:1:248:1893:25c8:1946'))->toBeTrue();
});

/** Runs $body with PAIDER_FETCH_ALLOW set to $value. */
function withAllowlist(string $value, Closure $body): void
{
    ProjectEnv::forget();
    putenv(UrlGuard::ALLOW_VAR.'='.$value);

    try {
        $body();
    } finally {
        putenv(UrlGuard::ALLOW_VAR);
        ProjectEnv::forget();
    }
}

test('an allowlisted host may resolve to a private address', function () {
    // Self-hosted infrastructure legitimately lives on a private address. Refusing it forever
    // is the wrong default for someone who owns the network — but it stays opt-in and per-host.
    withAllowlist('git.internal.example', function () {
        // An IP literal, not a hostname: no DNS is involved, so the assertion is deterministic
        // on any machine. The refused case is asserted FIRST so the two states are proven
        // different — an allowlist test that only checks the allowed state would pass even if
        // the guard allowed everything.
        expect(UrlGuard::inspect('http://192.168.1.10/')['ok'] ?? false)->toBeFalse();
    });

    withAllowlist('192.168.1.10', function () {
        $verdict = UrlGuard::inspect('http://192.168.1.10/');

        expect($verdict['ok'])->toBeTrue();
        expect($verdict['ips'])->toBe(['192.168.1.10']);
    });
});

test('allowlisting one private host does not allow any other', function () {
    withAllowlist('192.168.1.10', function () {
        expect(UrlGuard::inspect('http://192.168.1.11/')['ok'] ?? false)->toBeFalse();
        expect(UrlGuard::inspect('http://127.0.0.1/')['ok'] ?? false)->toBeFalse();
        expect(UrlGuard::inspect('http://169.254.169.254/')['ok'] ?? false)->toBeFalse();
    });
});

test('a redirect from an allowlisted host to a different private address is still refused', function () {
    // The allowlist is per-hop, not a session-wide "private is fine now". Otherwise one
    // allowlisted host could bounce the agent anywhere on the LAN.
    withAllowlist('192.168.1.10', function () {
        $tool = fetchToolWith([
            new Response(302, ['Location' => 'http://192.168.1.99/secrets']),
            new Response(200, [], 'INTERNAL'),
        ], $history);

        $result = $tool->execute(allow('http://192.168.1.10/start'));

        expect($result->ok)->toBeFalse();
        expect($result->output)->toContain('192.168.1.99');
        expect($history)->toHaveCount(1);
    });
});

test('the allowlist matches exactly — no suffix or wildcard matching', function () {
    // str_ends_with($host, 'example.com') would also match 'notexample.com'.
    withAllowlist('example.com', function () {
        expect(UrlGuard::allowlist())->toBe(['example.com']);
        expect(UrlGuard::inspect('http://127.0.0.1/')['ok'] ?? false)->toBeFalse();
    });

    // An IP literal needs no DNS, so this actually reaches the allowlist match — a hostname
    // like '1192.168.1.10' does not: it fails FILTER_VALIDATE_IP and doesn't resolve, so it
    // dies earlier at "could not resolve" and never exercises the suffix-match code at all.
    withAllowlist('92.168.1.10', function () {
        expect(UrlGuard::inspect('http://192.168.1.10/')['ok'] ?? false)->toBeFalse();
    });
});

test('the allowlist is parsed tolerantly — spaces, case and empty entries', function () {
    withAllowlist('  Git.Example.COM , , searx.example.com  ', function () {
        expect(UrlGuard::allowlist())->toBe(['git.example.com', 'searx.example.com']);
    });
});

test('an unset or empty allowlist blocks everything private, as before', function () {
    withAllowlist('', function () {
        expect(UrlGuard::allowlist())->toBe([]);
        expect(UrlGuard::inspect('http://192.168.1.10/')['ok'] ?? false)->toBeFalse();
    });
});

test('an allowlisted host still cannot use a non-http scheme or carry credentials', function () {
    withAllowlist('192.168.1.10', function () {
        expect(UrlGuard::inspect('file://192.168.1.10/etc/passwd')['ok'] ?? false)->toBeFalse();
        expect(UrlGuard::inspect('http://user:pw@192.168.1.10/')['ok'] ?? false)->toBeFalse();
    });
});
