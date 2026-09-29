<?php

namespace App\Providers;

use App\Support\ShellEnv;
use App\Tools\McpTool;
use App\Tools\ToolResult;
use Mcp\Client;
use Mcp\Client\Transport\StdioTransport;
use Mcp\Schema\Content\TextContent;
use Throwable;

/**
 * Real MCP stdio client — the piece that was a placeholder until now.
 *
 * Why this class exists: `McpClient::discoverViaSdk()` advertised a stdio integration that never
 * opened a transport, and its executor returned the literal string "SDK execute not yet wired".
 * The capability was already in the dependency (`mcp/sdk` ships a working Client + StdioTransport)
 * one adapter away. This is that adapter.
 *
 * ## Security: the environment scrub is the whole ballgame
 *
 * `StdioTransport::spawnProcess()` calls `proc_open()` — a SECOND spawn site outside
 * `ShellTool`, and therefore outside the `ShellEnv` scrub that `DECISIONS.md` §17 put there
 * precisely so an approved subprocess cannot inherit live provider API keys. An MCP server
 * configured in a project's `mcp.json` is arbitrary third-party code; without the scrub, merely
 * configuring one would hand it `OPENROUTER_API_KEY` and friends. Every child this class spawns
 * gets `ShellEnv::build()` — the same allowlist, for the same reason.
 *
 * ## Why connect/disconnect per call
 *
 * The transport owns a child process and only reaps it in `close()`. Holding one connection per
 * server for the life of the process would leak the child if the CLI exits without unwinding, so
 * each operation connects, does its work, and disconnects in a `finally`. An orphaned MCP server
 * is worse than a slow one: it holds a pipe and a slot, and nothing in the system knows to kill
 * it.
 */
final class McpStdioClient
{
    public function __construct(
        private readonly string $serverName,
        private readonly string $command,
        /** @var array<int, string> */
        private readonly array $args = [],
        private readonly ?string $cwd = null,
    ) {
        if (trim($this->command) === '') {
            throw new \InvalidArgumentException("MCP server '{$this->serverName}' has an empty command");
        }
    }

    /**
     * Build from one `mcp.json` server entry. Supports the `command`/`args` stdio shape and
     * nothing else — an `url` entry is an HTTP server and belongs to `McpdClient`, not here.
     *
     * @param  array<string, mixed>  $config
     */
    public static function fromConfig(string $name, array $config): ?self
    {
        $command = $config['command'] ?? null;

        if (! is_string($command) || $command === '') {
            return null;
        }

        $args = [];
        foreach (($config['args'] ?? []) as $arg) {
            if (is_string($arg)) {
                $args[] = $arg;
            }
        }

        $cwd = $config['cwd'] ?? null;

        return new self(
            serverName: is_string($name) ? $name : 'mcp',
            command: $command,
            args: $args,
            cwd: is_string($cwd) && $cwd !== '' ? $cwd : null,
        );
    }

    /**
     * Discover this server's tools. Returns [] only for a server that genuinely exposes none —
     * a server that cannot be started raises, so a broken config is visible rather than
     * silently indistinguishable from "this server has no tools".
     *
     * @return array<int, McpTool>
     */
    public function tools(): array
    {
        return $this->withClient(function (Client $client): array {
            $tools = [];

            foreach ($client->listTools()->tools as $definition) {
                $tools[] = new McpTool(
                    toolName: $this->qualify($definition->name),
                    description: $definition->description ?? "MCP tool {$definition->name} on {$this->serverName}",
                    inputSchema: $definition->inputSchema,
                    executor: fn (array $input, bool $approved): ToolResult => $this->call($definition->name, $input, $approved),
                );
            }

            return $tools;
        });
    }

    /**
     * Call one tool by its ORIGINAL (unqualified) name.
     *
     * Approval mirrors `McpdClient` exactly: an unapproved call returns `needs_approval` and
     * `Loop::needsRetry()`/`retryWithApproval()` re-invoke us with $approved=true after
     * `Gate::decide()`. Note the approval flag is the PHP `$approved` parameter, never an
     * `$input['approved']` key — the Tool contract calls that substitution out explicitly, and a
     * model-controlled `approved` key would be a trivially forgeable grant.
     */
    public function call(string $toolName, array $input, bool $approved): ToolResult
    {
        if (! $approved) {
            return ToolResult::fail('MCP tool requires approval', ['needs_approval' => true]);
        }

        try {
            return $this->withClient(function (Client $client) use ($toolName, $input): ToolResult {
                $result = $client->callTool($toolName, $input);

                $text = '';
                foreach ($result->content as $item) {
                    if ($item instanceof TextContent) {
                        $text .= $item->text;
                    }
                }

                return $result->isError ? ToolResult::fail($text) : ToolResult::ok($text);
            });
        } catch (Throwable $e) {
            return ToolResult::fail("MCP tool {$toolName} failed: ".$e->getMessage());
        }
    }

    /**
     * Namespaced so two servers exposing `search` cannot collide in one tool list. The hash keeps
     * it collision-proof: sanitising alone maps `a-b` and `a_b` onto the same string, and the
     * loop's tool-name matching is exact.
     */
    private function qualify(string $toolName): string
    {
        return 'mcp__'.$this->serverName.'__'.$toolName;
    }

    /**
     * @template T
     *
     * @param  callable(Client): T  $work
     * @return T
     */
    private function withClient(callable $work): mixed
    {
        $client = Client::builder()
            ->setClientInfo('paider', '1.0.0')
            // 5s, not 30: a missing binary and a wedged server both surface as an init timeout,
            // and 30s per server multiplied across a config is a visibly hung CLI. Discovery runs
            // on EVERY `paider chat` start, so this is paid before the first prompt. A genuinely
            // slow server can raise PAIDER_MCP_INIT_TIMEOUT rather than have the default ballooned.
            ->setInitTimeout((int) (getenv('PAIDER_MCP_INIT_TIMEOUT') ?: 5))
            ->setRequestTimeout(60)
            ->setMaxRetries(0)
            ->build();

        $transport = new StdioTransport(
            command: $this->command,
            args: $this->args,
            cwd: $this->cwd,
            env: ShellEnv::build(),
        );

        $client->connect($transport);

        try {
            return $work($client);
        } finally {
            // Reap the child even when the call throws — an orphaned server holds its pipe.
            $client->disconnect();
        }
    }
}
