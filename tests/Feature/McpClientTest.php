<?php

use App\Providers\McpClient;
use App\Tools\ToolResult;

// ASSUMPTION: McpClient::discoverViaSdk() never spawns a subprocess or opens a stdio pipe
// to a real MCP server in the shipped v0.7.0 code path — it only reads an inline "tools"
// array out of mcp.json and, when present, builds McpTool adapters whose executor is a
// hardcoded closure returning ToolResult::fail("... SDK execute not yet wired"). There is
// no JSON-RPC framing anywhere in this class to round-trip against. Building a fixture
// stdio MCP server (per the item's "OR" option) would therefore be dead test scaffolding
// that the class under test never calls — exactly the "fixture constructs its own
// precondition" trap the item warns about, since the test would pass regardless of whether
// McpClient itself is broken. Instead, "tool call round-trip" and "error path" below both
// exercise the REAL, only round trip this class performs: config -> discovered Tool ->
// execute() -> the deterministic fail ToolResult it ships today. If a future version wires
// real SDK execution, these tests will fail loudly (wrong output string) and must be revisited.

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

function withMcpEnabled(Closure $body, ?string $value = '1'): void
{
    $original = getenv(McpClient::ENV_FLAG);

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
    }
}

afterEach(function () {
    // Never let PAIDER_MCP leak into other test files.
    putenv(McpClient::ENV_FLAG);
});

// --- enabled() ---------------------------------------------------------

it('is disabled when PAIDER_MCP is unset', function () {
    withMcpEnabled(function () {
        expect(McpClient::enabled())->toBeFalse();
    }, null);
});

it('is disabled for falsy PAIDER_MCP values', function () {
    foreach (['0', 'false', 'off', 'no', ''] as $val) {
        withMcpEnabled(function () use ($val) {
            expect(McpClient::enabled())->toBeFalse();
        }, $val);
    }
});

it('is enabled for truthy PAIDER_MCP values, case-insensitively', function () {
    foreach (['1', 'true', 'TRUE', 'on', 'ON', 'yes', 'Yes'] as $val) {
        withMcpEnabled(function () use ($val) {
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

    withMcpEnabled(function () use ($root) {
        expect(McpClient::tools($root))->toBe([]);
    }, null);
});

it('returns no tools when mcp.json is missing', function () {
    $root = mcpProjectDir();

    withMcpEnabled(function () use ($root) {
        expect(McpClient::tools($root))->toBe([]);
    });
});

it('returns no tools when mcp.json is malformed JSON', function () {
    $root = mcpProjectDir(['mcp.json' => '{ this is not json']);

    withMcpEnabled(function () use ($root) {
        expect(McpClient::tools($root))->toBe([]);
    });
});

it('returns no tools when mcp.json decodes to a non-array', function () {
    $root = mcpProjectDir(['mcp.json' => json_encode('just a string')]);

    withMcpEnabled(function () use ($root) {
        expect(McpClient::tools($root))->toBe([]);
    });
});

it('returns no tools when the servers list is empty or absent', function () {
    $root = mcpProjectDir(['mcp.json' => json_encode(['mcpServers' => []])]);

    withMcpEnabled(function () use ($root) {
        expect(McpClient::tools($root))->toBe([]);
    });
});

it('skips non-array server entries', function () {
    $root = mcpProjectDir(['mcp.json' => json_encode([
        'mcpServers' => ['broken' => 'not-an-array'],
    ])]);

    withMcpEnabled(function () use ($root) {
        expect(McpClient::tools($root))->toBe([]);
    });
});

// --- tools(): discovery shapes -------------------------------------------

it('builds a fallback stub tool per server when no inline tools are configured', function () {
    $root = mcpProjectDir(['mcp.json' => json_encode([
        'mcpServers' => ['My Server!' => ['command' => 'irrelevant']],
    ])]);

    withMcpEnabled(function () use ($root) {
        $tools = McpClient::tools($root);

        expect($tools)->toHaveCount(1);
        expect($tools[0]->name())->toBe('mcp__My_Server___list');
        expect($tools[0]->description())->toContain("MCP server 'My Server!'");

        $result = $tools[0]->execute([], false);
        expect($result)->toBeInstanceOf(ToolResult::class);
        expect($result->ok)->toBeFalse();
        expect($result->output)->toBe('MCP SDK not installed or server unavailable');
    });
});

it('discovers inline tools from a server config under the mcpServers shape', function () {
    $root = mcpProjectDir(['mcp.json' => json_encode([
        'mcpServers' => [
            'files' => [
                'tools' => [
                    [
                        'name' => 'read_file',
                        'description' => 'Reads a file',
                        'inputSchema' => ['type' => 'object', 'properties' => ['path' => ['type' => 'string']]],
                    ],
                ],
            ],
        ],
    ])]);

    withMcpEnabled(function () use ($root) {
        $tools = McpClient::tools($root);

        expect($tools)->toHaveCount(1);
        expect($tools[0]->name())->toBe('read_file');
        expect($tools[0]->description())->toBe('Reads a file');
        expect($tools[0]->inputSchema())->toBe(['type' => 'object', 'properties' => ['path' => ['type' => 'string']]]);
    });
});

it('discovers inline tools under the list-shaped servers key, falling back to serverName "mcp"', function () {
    $root = mcpProjectDir(['mcp.json' => json_encode([
        'servers' => [
            ['tools' => [['name' => 'ping']]],
        ],
    ])]);

    withMcpEnabled(function () use ($root) {
        $tools = McpClient::tools($root);

        expect($tools)->toHaveCount(1);
        expect($tools[0]->name())->toBe('ping');
        expect($tools[0]->description())->toBe('MCP tool ping from mcp');
    });
});

it('skips tool definitions that are missing a string name', function () {
    $root = mcpProjectDir(['mcp.json' => json_encode([
        'mcpServers' => ['demo' => ['tools' => [
            ['description' => 'no name here'],
            'not-even-an-array',
            ['name' => 'valid_one'],
        ]]],
    ])]);

    withMcpEnabled(function () use ($root) {
        $tools = McpClient::tools($root);

        expect($tools)->toHaveCount(1);
        expect($tools[0]->name())->toBe('valid_one');
    });
});

it('defaults an unnamed inline tool description and schema', function () {
    $root = mcpProjectDir(['mcp.json' => json_encode([
        'mcpServers' => ['demo' => ['tools' => [['name' => 'bare']]]],
    ])]);

    withMcpEnabled(function () use ($root) {
        $tools = McpClient::tools($root);

        expect($tools[0]->description())->toBe('MCP tool bare from demo');
        expect($tools[0]->inputSchema())->toHaveKey('type', 'object');
    });
});

// --- tools(): the execute() round trip & error path -----------------------

it('round-trips a discovered inline tool call to the deterministic not-wired failure', function () {
    $root = mcpProjectDir(['mcp.json' => json_encode([
        'mcpServers' => ['weather' => ['tools' => [
            ['name' => 'get_forecast', 'inputSchema' => ['type' => 'object']],
        ]]],
    ])]);

    withMcpEnabled(function () use ($root) {
        $tools = McpClient::tools($root);
        $tool = $tools[0];

        $result = $tool->execute(['city' => 'Dallas'], true);

        expect($result)->toBeInstanceOf(ToolResult::class);
        expect($result->ok)->toBeFalse();
        expect($result->output)->toBe('MCP tool get_forecast on weather: SDK execute not yet wired');
        expect($result->meta)->toBe([]);
    });
});

it('returns a failing ToolResult on the error path for a server with no discoverable tools', function () {
    $root = mcpProjectDir(['mcp.json' => json_encode([
        'mcpServers' => ['empty' => ['tools' => []]],
    ])]);

    withMcpEnabled(function () use ($root) {
        $tools = McpClient::tools($root);

        expect($tools)->toHaveCount(1);

        $result = $tools[0]->execute(['anything' => true]);

        expect($result->ok)->toBeFalse();
        expect($result->output)->toBe('MCP SDK not installed or server unavailable');
    });
});
