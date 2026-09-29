<?php

use App\Providers\McpClient;
use App\Providers\McpStdioClient;

function paiderMcpProject(array $servers): string
{
    $root = sys_get_temp_dir().'/paider-stdio-'.bin2hex(random_bytes(6));
    mkdir($root);
    file_put_contents($root.'/mcp.json', json_encode(['mcpServers' => $servers]));

    return $root;
}

function paiderMcpCleanup(string $root): void
{
    if (is_file($root.'/mcp.json')) {
        unlink($root.'/mcp.json');
    }
    rmdir($root);
}

// Resolved inside a test, not at file scope: base_path() needs the app booted, and a
// file-scope call runs before the Laravel Zero container exists.
function paiderFixtureServer(): string
{
    return base_path('tests/Fixtures/stdio-server.php');
}

beforeEach(function () {
    putenv('PAIDER_MCP=1');
    putenv('PAIDER_MCPD_URL');
    // A key the scrub MUST NOT hand to a child process. Set for the duration of the scrub test.
    putenv('PAIDER_TEST_FAKE_SECRET=super-secret-value');
});

afterEach(function () {
    putenv('PAIDER_MCP');
    putenv('PAIDER_MCPD_URL');
    putenv('PAIDER_TEST_FAKE_SECRET');
});

it('discovers real tool definitions from a stdio server, not a stub', function () {
    $root = paiderMcpProject(['fixture' => ['command' => PHP_BINARY, 'args' => [paiderFixtureServer()]]]);

    try {
        $tools = McpClient::tools($root);
        $names = array_map(fn ($t) => $t->name(), $tools);

        // The stub placeholder registered exactly one fake "mcp__<server>__list" tool.
        // Two real, distinctly-named tools is the difference between wired and placeholder.
        expect($names)->toContain('mcp__fixture__echo')
            ->and($names)->toContain('mcp__fixture__env')
            ->and($names)->not->toContain('mcp__fixture__list');

        $echo = collect($tools)->firstWhere(fn ($t) => $t->name() === 'mcp__fixture__echo');
        expect($echo->description())->toContain('Echo the message')
            ->and($echo->inputSchema()['required'])->toBe(['message']);
    } finally {
        paiderMcpCleanup($root);
    }
});

it('round-trips a real tool call end to end over stdio', function () {
    $root = paiderMcpProject(['fixture' => ['command' => PHP_BINARY, 'args' => [paiderFixtureServer()]]]);

    try {
        $tools = collect(McpClient::tools($root));
        $echo = $tools->firstWhere(fn ($t) => $t->name() === 'mcp__fixture__echo');

        // Unapproved: refuses and asks for the gate, exactly like McpdClient.
        $denied = $echo->execute(['message' => 'hello']);
        expect($denied->ok)->toBeFalse()
            ->and($denied->meta['needs_approved'] ?? $denied->meta['needs_approval'] ?? null)->toBeTrue();

        // Approved by Loop after Gate::decide(): the call really reaches the child process.
        $result = $echo->execute(['message' => 'hello fixture'], true);
        expect($result->ok)->toBeTrue()
            ->and($result->output)->toBe('hello fixture');
    } finally {
        paiderMcpCleanup($root);
    }
});

it('never passes a live secret into the MCP server child process', function () {
    $root = paiderMcpProject(['fixture' => ['command' => PHP_BINARY, 'args' => [paiderFixtureServer()]]]);

    try {
        $tools = collect(McpClient::tools($root));
        $env = $tools->firstWhere(fn ($t) => $t->name() === 'mcp__fixture__env');

        // Ground truth from INSIDE the child, not an assertion about our own code: if the scrub
        // regressed, this reads the child env and finds the key. StdioTransport::spawnProcess()
        // is a second proc_open outside ShellTool, which is exactly the DECISIONS.md §17 hazard.
        $result = $env->execute([], true);
        expect($result->ok)->toBeTrue();

        $childEnv = json_decode($result->output, true);
        expect($childEnv)->toBeArray()
            ->and($childEnv)->not->toHaveKey('PAIDER_TEST_FAKE_SECRET')
            ->and($childEnv)->not->toHaveKey('OPENROUTER_API_KEY')
            ->and($childEnv)->not->toHaveKey('ANTHROPIC_API_KEY');
    } finally {
        paiderMcpCleanup($root);
    }
});

it('surfaces a server that cannot start instead of silently dropping it', function () {
    $root = paiderMcpProject(['broken' => ['command' => '/nonexistent/definitely-not-a-real-binary']]);

    try {
        // A config error must be VISIBLE. The old stub made a broken server look identical to
        // a server with no tools, which is how "my MCP tools disappeared" becomes undebuggable.
        expect(fn () => McpClient::tools($root))
            ->toThrow(RuntimeException::class);
    } finally {
        paiderMcpCleanup($root);
    }
});

it('ignores a url-only entry, which is an HTTP server not a stdio one', function () {
    $root = paiderMcpProject(['remote' => ['url' => 'https://example.com/mcp']]);

    try {
        // No `command` => not stdio. Silently skipped rather than fatal; HTTP is McpdClient's job.
        expect(McpClient::tools($root))->toBe([]);
    } finally {
        paiderMcpCleanup($root);
    }
});

it('namespaces tools so two servers exposing the same tool name cannot collide', function () {
    $root = paiderMcpProject([
        'alpha' => ['command' => PHP_BINARY, 'args' => [paiderFixtureServer()]],
        'beta' => ['command' => PHP_BINARY, 'args' => [paiderFixtureServer()]],
    ]);

    try {
        $names = array_map(fn ($t) => $t->name(), McpClient::tools($root));
        expect($names)->toContain('mcp__alpha__echo')
            ->and($names)->toContain('mcp__beta__echo')
            ->and(count($names))->toBe(4);
    } finally {
        paiderMcpCleanup($root);
    }
});

it('rejects an empty command rather than spawning a shell with nothing', function () {
    expect(fn () => new McpStdioClient('x', '  '))
        ->toThrow(InvalidArgumentException::class);
});

it('ignores non-string args from a hand-edited config', function () {
    $client = McpStdioClient::fromConfig('x', ['command' => 'php', 'args' => ['a', 1, null, 'b']]);
    expect($client)->toBeInstanceOf(McpStdioClient::class);
});
