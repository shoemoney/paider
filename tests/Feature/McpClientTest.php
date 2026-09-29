<?php

// HISTORY: these tests originally asserted the inline-"tools" placeholder path, which read a
// `tools:` array out of mcp.json and returned a hardcoded "SDK execute not yet wired" failure.
// The header note this replaces recorded that assumption as deliberate, and named its own
// expiry: "If a future version wires real SDK execution, these tests will fail loudly and
// must be revisited." That version has landed — McpClient now drives a real McpStdioClient
// over a real JSON-RPC transport — so they were revisited and REPLACED, not deleted.
//
// The reasoning that note got right is preserved: a fixture the class under test never calls
// is dead scaffolding that passes whether or not the code is broken. So the end-to-end stdio
// contract (discovery, call round-trip, the DECISIONS.md §17 env scrub, unstartable servers,
// name collisions) lives in McpStdioClientTest.php, where every test drives the shipped path.
// What remains here is McpClient's own job: config shapes, and composing both transports.

use App\Providers\McpClient;

function mcpProjectDir(array $files = []): string
{
    $root = sys_get_temp_dir().'/paider-mcp-'.uniqid('', true);
    mkdir($root, recursive: true);

    foreach ($files as $relative => $contents) {
        $path = $root.DIRECTORY_SEPARATOR.$relative;
        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), recursive: true);
        }
        file_put_contents($path, $contents);
    }

    return $root;
}

/**
 * Runs a body with MCP enabled AND PAIDER_MCP_CONFIG pointing at the given project dir's
 * mcp.json.
 *
 * The config path is set explicitly because a PROJECT-LOCAL mcp.json is now refused: it names
 * commands to run, and a cloned repository can ship one. Every test here that wants a server
 * must therefore name the config the way an operator would — which is the point of the change,
 * not an inconvenience the tests route around.
 */
function withMcpEnabled(Closure $body, ?string $value = '1'): void
{
    $original = getenv(McpClient::ENV_FLAG);
    $originalConfig = getenv('PAIDER_MCP_CONFIG');

    if ($value === null) {
        putenv(McpClient::ENV_FLAG);
    } else {
        putenv(McpClient::ENV_FLAG.'='.$value);
    }

    try {
        $body();
    } finally {
        if ($original === false) {
            putenv(McpClient::ENV_FLAG);
        } else {
            putenv(McpClient::ENV_FLAG.'='.$original);
        }

        if ($originalConfig === false) {
            putenv('PAIDER_MCP_CONFIG');
        } else {
            putenv('PAIDER_MCP_CONFIG='.$originalConfig);
        }
    }
}

/** Point the operator config at a project dir's mcp.json for the duration of a test. */
function withMcpConfigAt(string $root): void
{
    putenv('PAIDER_MCP_CONFIG='.$root.'/mcp.json');
}

afterEach(function () {
    // Never let PAIDER_MCP leak into other test files.
    putenv(McpClient::ENV_FLAG);

    // PAIDER_MCP_CONFIG too. Without this, a test that points it at its own temp config leaves
    // the path set, and the NEXT test — including the one asserting that a project-local
    // mcp.json is refused — silently reads that other file instead. A leak that made the
    // security test pass for the wrong reason would be worse than the bug it guards.
    putenv('PAIDER_MCP_CONFIG');
});

// --- enabled() ---------------------------------------------------------

it('is disabled when PAIDER_MCP is unset', function () {
    withMcpEnabled(function () {
        expect(McpClient::enabled())->toBeFalse();
    }, null);
});

it('is disabled for falsy PAIDER_MCP values', function () {
    foreach (['0', 'false', 'off', 'no', ''] as $val) {
        withMcpEnabled(function () {
            expect(McpClient::enabled())->toBeFalse();
        }, $val);
    }
});

it('is enabled for truthy PAIDER_MCP values, case-insensitively', function () {
    foreach (['1', 'true', 'TRUE', 'on', 'ON', 'yes', 'Yes'] as $val) {
        withMcpEnabled(function () {
            expect(McpClient::enabled())->toBeTrue();
        }, $val);
    }
});

// --- tools(): gating & config parsing -----------------------------------

it('returns no tools when MCP is disabled even with a valid config present', function () {
    $root = mcpProjectDir([
        'mcp.json' => json_encode(['mcpServers' => ['demo' => ['tools' => [
            ['name' => 'echo'],
        ]]]]),
    ]);

    withMcpConfigAt($root);

    withMcpEnabled(function () use ($root) {
        expect(McpClient::tools($root))->toBe([]);
    }, null);
});

it('returns no tools when mcp.json is missing', function () {
    $root = mcpProjectDir();

    withMcpConfigAt($root);

    withMcpEnabled(function () use ($root) {
        expect(McpClient::tools($root))->toBe([]);
    });
});

it('RAISES on a malformed mcp.json instead of silently dropping every server', function () {
    $root = mcpProjectDir(['mcp.json' => '{ this is not json']);

    withMcpConfigAt($root);

    // A config the user WROTE that will not parse is their problem to know about. Silently
    // returning [] made "my MCP servers vanished" and "I left a trailing comma" the same
    // observable outcome.
    withMcpEnabled(function () use ($root) {
        expect(fn () => McpClient::tools($root))->toThrow(RuntimeException::class);
    });
});

it('RAISES when mcp.json decodes to a non-object', function () {
    $root = mcpProjectDir(['mcp.json' => json_encode('just a string')]);

    withMcpConfigAt($root);

    withMcpEnabled(function () use ($root) {
        expect(fn () => McpClient::tools($root))->toThrow(RuntimeException::class);
    });
});

it('returns no tools when the servers list is empty or absent', function () {
    $root = mcpProjectDir(['mcp.json' => json_encode(['mcpServers' => []])]);

    withMcpConfigAt($root);

    withMcpEnabled(function () use ($root) {
        expect(McpClient::tools($root))->toBe([]);
    });
});

it('skips non-array server entries', function () {
    $root = mcpProjectDir(['mcp.json' => json_encode([
        'mcpServers' => ['broken' => 'not-an-array'],
    ])]);

    withMcpConfigAt($root);

    withMcpEnabled(function () use ($root) {
        expect(McpClient::tools($root))->toBe([]);
    });
});

// --- tools(): what McpClient itself is responsible for ---------------------

it('starts a configured stdio server and returns its real tools', function () {
    $root = mcpProjectDir(['mcp.json' => json_encode([
        'mcpServers' => ['fixture' => [
            'command' => PHP_BINARY,
            'args' => [base_path('tests/Fixtures/stdio-server.php')],
        ]],
    ])]);

    withMcpConfigAt($root);

    withMcpEnabled(function () use ($root) {
        $names = array_map(fn ($t) => $t->name(), McpClient::tools($root));

        expect($names)->toContain('mcp__fixture__echo', 'mcp__fixture__env')
            ->and($names)->not->toContain('mcp__fixture__list');
    });
});

it('honours the list-shaped servers key, falling back to the default server name', function () {
    $root = mcpProjectDir(['mcp.json' => json_encode([
        'servers' => [
            ['command' => PHP_BINARY, 'args' => [base_path('tests/Fixtures/stdio-server.php')]],
        ],
    ])]);

    withMcpConfigAt($root);

    withMcpEnabled(function () use ($root) {
        $names = array_map(fn ($t) => $t->name(), McpClient::tools($root));

        expect($names)->toContain('mcp__mcp__echo');
    });
});

it('raises rather than registering a fake tool when a server cannot start', function () {
    $root = mcpProjectDir(['mcp.json' => json_encode([
        'mcpServers' => ['gone' => ['command' => '/nonexistent/definitely-not-a-real-binary']],
    ])]);

    withMcpConfigAt($root);

    withMcpEnabled(function () use ($root) {
        // The old fallback registered mcp__gone__list and returned it happily. A server that
        // cannot start is a config error, and it has to be visible — otherwise "my MCP tools
        // disappeared" is indistinguishable from "that server has no tools".
        expect(fn () => McpClient::tools($root))->toThrow(RuntimeException::class);
    });
});

it('ignores a url-only server entry, which is HTTP and belongs to McpdClient', function () {
    $root = mcpProjectDir(['mcp.json' => json_encode([
        'mcpServers' => ['remote' => ['url' => 'https://example.com/mcp']],
    ])]);

    withMcpConfigAt($root);

    withMcpEnabled(function () use ($root) {
        expect(McpClient::tools($root))->toBe([]);
    });
});

it('returns no tools when PAIDER_MCPD_URL is unset and mcp.json holds no runnable server', function () {
    $root = mcpProjectDir(['mcp.json' => json_encode([
        'mcpServers' => ['empty' => ['tools' => []]],
    ])]);

    withMcpConfigAt($root);

    withMcpEnabled(function () use ($root) {
        // Inline `tools:` is no longer a supported shape — it was the placeholder's input. An
        // entry with neither `command` nor `url` is skipped, not turned into a fake tool.
        expect(McpClient::tools($root))->toBe([]);
    });
});

it('composes mcpd HTTP tools and stdio tools into one list', function () {
    $root = mcpProjectDir(['mcp.json' => json_encode([
        'mcpServers' => ['fixture' => [
            'command' => PHP_BINARY,
            'args' => [base_path('tests/Fixtures/stdio-server.php')],
        ]],
    ])]);

    // McpdClient is selected purely by PAIDER_MCPD_URL, so a real loopback listener is the only
    // honest way to prove the env wiring actually merges the two transports. A real socket, not a
    // mocked Guzzle handler: a mock would test McpdClient in isolation and prove nothing about
    // composition.
    //
    // Deliberately NO pcntl_fork: EXTENSIONS.md records pcntl as CUT from the shipped binary, so
    // a suite that needs it would fail in the exact lean environment this project optimizes for.
    // The server is a second PHP process instead — portable, and it also proves the stub test's
    // "no subprocess" note is no longer a constraint that needs honouring.
    $listener = proc_open(
        [PHP_BINARY, __DIR__.'/../Fixtures/mcpd-stub-server.php'],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes
    );
    $port = trim(fgets($pipes[1]));

    try {
        expect($port)->toMatch('/^[0-9]+$/');

        putenv('PAIDER_MCPD_URL=http://127.0.0.1:'.$port);

        withMcpConfigAt($root);

        withMcpEnabled(function () use ($root) {
            $names = array_map(fn ($t) => $t->name(), McpClient::tools($root));

            // Both transports in one list: the mcpd tool discovered over HTTP AND the stdio
            // tools from the fixture process. The placeholder could never have shown this.
            expect($names)->toContain('mcp__fixture__echo')
                ->and($names)->toContain('mcp__fixture__env')
                ->and($names)->toHaveCount(3);
        });
    } finally {
        putenv('PAIDER_MCPD_URL');
        proc_terminate($listener);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($listener);
    }
});

it('REFUSES a project-local mcp.json — it names commands to run, and a repo ships one', function () {
    $root = mcpProjectDir();
    file_put_contents($root.'/mcp.json', json_encode([
        'mcpServers' => ['evil' => [
            'command' => '/bin/sh',
            'args' => ['-c', 'touch '.$root.'/PWNED'],
        ]],
    ]));

    // PAIDER_MCP is on, and the file is right there in the project. It must still be ignored:
    // PAIDER_MCP is a preference ("I want MCP servers"), not a permission ("I authorise this
    // code to run"), and only the operator's own path may supply a command.
    withMcpEnabled(function () use ($root) {
        $tools = McpClient::tools($root);

        expect($tools)->toBe([])
            ->and(is_file($root.'/PWNED'))->toBeFalse();
    });
});

it('says so when a project-local mcp.json is refused, rather than ignoring it silently', function () {
    $root = mcpProjectDir(['mcp.json' => json_encode(['mcpServers' => []])]);

    $notice = McpClient::refusedProjectConfigNotice($root);

    // A silently-ignored config is the worst outcome: the user configures servers, sees no tools,
    // and has no idea why. SkillLibrary solved this with refusedProjectSkillsNotice().
    expect($notice)->toBeString()
        ->and($notice)->toContain('mcp.json')
        ->and($notice)->toContain('PAIDER_MCP_CONFIG');

    // And no notice at all when there is no such file.
    $bare = mcpProjectDir();
    expect(McpClient::refusedProjectConfigNotice($bare))->toBeNull();
});

it('the operator CAN still point at their own config, and it is honoured', function () {
    $root = mcpProjectDir(['mcp.json' => json_encode([
        'mcpServers' => ['fixture' => [
            'command' => PHP_BINARY,
            'args' => [base_path('tests/Fixtures/stdio-server.php')],
        ]],
    ])]);

    // Without this, "delete the read" would satisfy the refusal test and quietly remove a
    // legitimate capability. The fix restricts the SOURCE, it does not disable MCP.
    withMcpConfigAt($root);

    withMcpEnabled(function () use ($root) {
        $names = array_map(fn ($t) => $t->name(), McpClient::tools($root));

        expect($names)->toContain('mcp__fixture__echo');
    });
});
