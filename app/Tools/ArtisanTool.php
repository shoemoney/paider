<?php

namespace App\Tools;

use App\Tools\Contracts\Tool;

/**
 * v0.1 Laravel-host proof: exactly one hardcoded call (`php artisan route:list --json`),
 * not a general Artisan passthrough — `php artisan <anything>` boots the target app's
 * service providers, which is arbitrary code execution, so it gets its own approval gate
 * rather than being reachable through run_shell. See PLAN.md.
 *
 * There is NO allowlist anywhere in ShellTool, and this docblock used to imply there was one
 * ("a slot in run_shell's allowlist"). That was a description of a control which does not
 * exist, and it is the kind of sentence that makes a later reader believe they are safer
 * than they are: run_shell will execute any string a human approves. The only thing
 * separating an approved command from arbitrary code execution here is the Gate, which is why
 * this tool is hardcoded to one call instead of being a passthrough.
 *
 * UI-agnostic like ShellTool: the caller (Loop) resolves the approval decision and
 * passes it in as $input['approval'].
 */
class ArtisanTool implements Tool
{
    public function __construct(
        private readonly string $projectRoot,
    ) {}

    public function name(): string
    {
        return 'artisan';
    }

    public function description(): string
    {
        return 'Runs `php artisan route:list --json` in the project root. Requires an approval decision.';
    }

    public function inputSchema(): array
    {
        // 'approval' is deliberately not advertised here — it's a Loop-internal field
        // resolved by the gate, not something the model should ever be told to supply.
        return [
            'type' => 'object',
            'properties' => [],
            'required' => [],
        ];
    }

    // ponytail: unused, only read/write/patch/git use the out-of-band signal — see Tool::execute() doc
    public function execute(array $input, bool $approved = false): ToolResult
    {
        if (! array_key_exists('approval', $input)) {
            return ToolResult::fail('approval required', ['needs_approval' => true]);
        }

        if ($input['approval'] === 'deny') {
            return ToolResult::fail('denied');
        }

        if (! in_array($input['approval'], ['allow-once', 'allow-session'], true)) {
            return ToolResult::fail('approval required', ['needs_approval' => true]);
        }

        // Delegate execution to ShellTool: same non-blocking drain loop and timeout that
        // dispatchArtisan's real-Laravel-app case needs, instead of a second copy that can
        // deadlock on stderr. The command is a compile-time constant, no interpolation.
        $result = (new ShellTool($this->projectRoot, 60))->execute([
            'command' => 'php artisan route:list --json',
            'approval' => $input['approval'],
        ]);

        if (! $result->ok) {
            return ToolResult::fail($result->output);
        }

        try {
            $decoded = json_decode($result->output, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return ToolResult::fail('could not parse route:list output');
        }

        if (! is_array($decoded)) {
            return ToolResult::fail('could not parse route:list output');
        }

        $rows = array_map(
            fn ($route) => [
                'route' => is_array($route) ? ($route['uri'] ?? null) : null,
                'method' => is_array($route) ? ($route['method'] ?? null) : null,
                'action' => is_array($route) ? ($route['action'] ?? null) : null,
            ],
            $decoded,
        );

        return ToolResult::ok(json_encode($rows, JSON_THROW_ON_ERROR));
    }
}
