<?php

namespace App\Providers;

use App\Tools\Contracts\Tool;
use Mcp\Exception\ConnectionException;
use RuntimeException;

/**
 * MCP client provider — composes two transports into one tool list:
 *   - mcpd over HTTP (McpdClient), opt-in via PAIDER_MCPD_URL
 *   - stdio servers from mcp.json (McpStdioClient), opt-in via PAIDER_MCP
 *
 * Registered via ChatCommand::buildTools() / RunCommand::buildTools().
 */
class McpClient
{
    public const ENV_FLAG = 'PAIDER_MCP';

    public const CONFIG_FILE = 'mcp.json';

    /**
     * Whether MCP is enabled via environment.
     * Accepts 1/true/on/yes in any case, like Gate::enabledInEnvironment().
     */
    public static function enabled(): bool
    {
        $val = getenv(self::ENV_FLAG);

        if ($val === false) {
            $val = $_ENV[self::ENV_FLAG] ?? false;
        }

        return (bool) filter_var($val, FILTER_VALIDATE_BOOL);
    }

    /**
     * Compose the MCP tool list: mcpd HTTP tools (always, if PAIDER_MCPD_URL is set) plus stdio
     * servers declared in mcp.json (only when PAIDER_MCP is on).
     *
     * A stdio server that cannot start raises — see the catch in the loop below.
     *
     * @return array<int, Tool>
     */
    public static function tools(string $projectRoot): array
    {
        if (! self::enabled()) {
            return McpdClient::toolsFromEnvironment();
        }

        $mcpdTools = McpdClient::toolsFromEnvironment();

        $configPath = rtrim($projectRoot, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.self::CONFIG_FILE;

        if (! is_file($configPath)) {
            return $mcpdTools;
        }

        try {
            $raw = file_get_contents($configPath);
            $config = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return $mcpdTools;
        }

        if (! is_array($config)) {
            return $mcpdTools;
        }

        // Support both {"mcpServers": {"name": {...}}} and {"servers": [...]} shapes
        $servers = $config['mcpServers'] ?? $config['servers'] ?? [];

        if (! is_array($servers) || $servers === []) {
            return $mcpdTools;
        }

        $tools = $mcpdTools;

        foreach ($servers as $name => $serverConfig) {
            if (! is_array($serverConfig)) {
                continue;
            }

            $serverName = is_string($name) ? $name : ($serverConfig['name'] ?? 'mcp');

            $client = McpStdioClient::fromConfig($serverName, $serverConfig);

            // No `command` means this entry isn't a stdio server — an `url` entry is an HTTP
            // server and belongs to McpdClient. Silently correct rather than fatal.
            if ($client === null) {
                continue;
            }

            try {
                array_push($tools, ...$client->tools());
            } catch (ConnectionException $e) {
                // A server that cannot start is a CONFIG ERROR, and it must be visible. Silently
                // dropping it would make "my MCP tools vanished" indistinguishable from "this
                // server has no tools" — the same class of quiet failure the removed stub
                // placeholder was guilty of, just with better manners about it.
                throw new RuntimeException(
                    "MCP server '{$serverName}' could not be started: ".$e->getMessage(),
                    previous: $e
                );
            }
        }

        return $tools;
    }
}
