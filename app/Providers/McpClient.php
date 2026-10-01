<?php

namespace App\Providers;

use App\Storage\ProjectEnv;
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
     * The MCP server config, resolved WITHOUT ever reading the project's own directory.
     *
     * ## Why $projectRoot/mcp.json is no longer consulted
     *
     * It was, and it was the last open hole in this project's clone-to-RCE boundary. Everything
     * else in it is walled: SkillLibrary refuses project-local skill directories unconditionally,
     * ProjectEnv's fromEnvironment() keeps credentials and endpoints out of a repository's reach,
     * LibraryIndex::refusesPath() refuses importing from a project path. Then `mcp.json` — which
     * a cloned repository SHIPS — named an arbitrary `command`/`args`/`cwd` that Paider spawned
     * with proc_open the moment PAIDER_MCP was on, at `chat` startup, with no Gate and no
     * approval on discovery.
     *
     * The ShellEnv scrub does not save it. That scrub exists so a subprocess cannot inherit
     * provider KEYS; it is not a sandbox, and the spawned process has the user's full filesystem
     * and network capability. SkillLibrary's own docblock names this exact shape — "a directory a
     * cloned REPOSITORY controls is exactly as dangerous as a repository-controlled .paider/.env"
     * — and by that standard mcp.json was a remote-code-execution vector with a config file
     * syntax.
     *
     * PAIDER_MCP is a *preference* (which servers I want), not a *permission* (I authorise this
     * code to run). Treating it as the latter is what closes it: only a path the operator names
     * can supply a command.
     *
     * Precedence, most specific first:
     *   1. $PAIDER_MCP_CONFIG — a real environment variable, one explicit path
     *   2. ~/.paider/mcp.json — the user's own home config, outside any repository
     *   3. nothing. A project-local mcp.json is REFUSED, not ignored in silence.
     *
     * Found by an adversarial review in the same session that closed the embedding-endpoint hole,
     * which is the strongest argument for having run it: the reviewer was handed the fix and
     * immediately identified the identical pattern one file over.
     */
    public static function configPath(): ?string
    {
        $explicit = ProjectEnv::fromEnvironment('PAIDER_MCP_CONFIG');

        if (is_string($explicit) && $explicit !== '') {
            return $explicit;
        }

        $home = getenv('HOME');

        return is_string($home) && $home !== ''
            ? rtrim($home, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.'.paider'.DIRECTORY_SEPARATOR.self::CONFIG_FILE
            : null;
    }

    /**
     * The project-local config that is deliberately NOT read, so the refusal can be reported to
     * the user rather than happening in silence.
     *
     * A silently-ignored file is the worst outcome available here: the user would configure MCP
     * servers, see no tools, and have no idea why. SkillLibrary solved the identical problem with
     * refusedProjectSkillsNotice(), and this is that idea applied to MCP.
     */
    public static function refusedProjectConfigNotice(?string $projectRoot = null): ?string
    {
        // `?? getcwd()`, NOT `$projectRoot !== '' ? ... : ...`. The signature is ?string, and
        // under strict comparison `null !== ''` is TRUE — so the fallback was unreachable for
        // the one value the type declares valid, and the method built the path "/mcp.json"
        // instead of "<cwd>/mcp.json". Latent while nothing called it; the moment it was wired
        // up the way its sibling is (no argument, or null) it would have failed closed into
        // silence, which is the opposite of what a refusal notice is for.
        $projectRoot = $projectRoot ?? (getcwd() ?: '.');
        $local = rtrim($projectRoot, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.self::CONFIG_FILE;

        if (! is_file($local)) {
            return null;
        }

        $home = self::configPath();

        return sprintf(
            'Ignored project-local %s: it names commands to run, and a cloned repository can ship one. '
            .'Point %s at yours (or move it to %s) to use MCP servers.',
            self::CONFIG_FILE,
            'PAIDER_MCP_CONFIG',
            $home ?? '~/.paider/'.self::CONFIG_FILE
        );
    }

    /**
     * Compose the MCP tool list: mcpd HTTP tools (always, if PAIDER_MCPD_URL is set) plus stdio
     * servers from the OPERATOR's config (only when PAIDER_MCP is on).
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

        $configPath = self::configPath();

        if ($configPath === null) {
            return $mcpdTools;
        }

        if (! is_file($configPath)) {
            return $mcpdTools;
        }

        try {
            $raw = file_get_contents($configPath);
            $config = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            // A config the user WROTE and that will not parse is their problem to know about.
            // Returning the mcpd tools and moving on makes "my MCP servers vanished" and "my
            // mcp.json has a trailing comma" the same observable outcome, which is how a
            // one-character typo becomes an afternoon.
            //
            // The message names the file, the parse error, AND the fix. Collision renders the
            // trace either way, so the actionable half has to be in the message itself — otherwise
            // a user who mistyped one character is told a JsonException occurred and left to work
            // out which file. Caught by a blind visual review of the ERROR path, a surface this
            // loop had never judged.
            //
            // It deliberately does NOT echo the file contents: a config may carry a token-bearing
            // URL, and an error screen is the worst place to leak one.
            throw new RuntimeException(
                "Could not parse {$configPath}: ".$e->getMessage()
                .'. Fix the JSON, or point PAIDER_MCP_CONFIG somewhere else.',
                previous: $e
            );
        }

        if (! is_array($config)) {
            throw new RuntimeException("{$configPath} must contain a JSON object");
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
