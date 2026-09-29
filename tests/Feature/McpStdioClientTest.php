<?php

use App\Providers\McpClient;
use App\Providers\McpStdioClient;

function paiderMcpProject(array $servers): string
{
    $root = sys_get_temp_dir().'/paider-stdio-'.bin2hex(random_bytes(6));
    mkdir($root);
    file_put_contents($root.'/mcp.json', json_encode(['mcpServers' => $servers]));

    // A project-local mcp.json is REFUSED now — it names commands to run and a cloned repository
    // can ship one. So these tests name it explicitly, the way an operator would. The refusal
    // itself is asserted in McpClientTest; without this line every test here would silently
    // exercise an empty config and pass for the wrong reason.
    putenv('PAIDER_MCP_CONFIG='.$root.'/mcp.json');

    return $root;
}

function paiderMcpCleanup(string $root): void
{
    putenv('PAIDER_MCP_CONFIG');

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
    // Never let the operator config path leak into another test file — a leftover path makes
    // the next file read a config it did not create.
    putenv('PAIDER_MCP_CONFIG');
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

it('reaps the child when the CALL fails, not only when connecting fails', function () {
    $server = sys_get_temp_dir().'/paider-reap-'.bin2hex(random_bytes(6)).'.php';
    file_put_contents($server, <<<'SRV'
<?php
// Answers initialize and tools/list, then fails the tool call — the shape that reaches the
// transport's normal lifecycle and then breaks mid-flight.
while (($line = fgets(STDIN)) !== false) {
    $m = json_decode(trim($line), true);
    if (!is_array($m) || !isset($m['id'])) { continue; }
    $id = $m['id'];
    if (($m['method'] ?? '') === 'initialize') {
        echo json_encode(['jsonrpc'=>'2.0','id'=>$id,'result'=>['protocolVersion'=>'2025-11-25','capabilities'=>['tools'=>(object)[]],'serverInfo'=>['name'=>'reap','version'=>'1']]]), "\n";
    } elseif (($m['method'] ?? '') === 'tools/list') {
        echo json_encode(['jsonrpc'=>'2.0','id'=>$id,'result'=>['tools'=>[['name'=>'boom','description'=>'fails','inputSchema'=>['type'=>'object']]]]]), "\n";
    } else {
        // Return an error result, which surfaces as a failing ToolResult through call().
        echo json_encode(['jsonrpc'=>'2.0','id'=>$id,'result'=>['content'=>[['type'=>'text','text'=>'kaboom']],'isError'=>true]]), "\n";
    }
    flush();
}
SRV);

    try {
        $client = new McpStdioClient('reap', PHP_BINARY, [$server]);
        $tool = $client->tools()[0];

        // A server-level error is a normal outcome, not a crash — the tool must report failure
        // and the connection must still be torn down cleanly by withClient()'s finally.
        $result = $tool->execute([], true);
        expect($result->ok)->toBeFalse()
            ->and($result->output)->toContain('kaboom');

        // And a second call still works, which proves the transport was properly released
        // rather than left in a half-open state by the error.
        expect($client->tools())->toHaveCount(1);
    } finally {
        unlink($server);
    }
});
