<?php

namespace App\Tools;

use App\Storage\EventLog;
use App\Storage\MemoryStore;
use App\Tools\Contracts\Tool;

/**
 * Lets the model record durable project facts and correct wrong ones.
 *
 * Ungated on purpose. Every other gated tool acts OUTSIDE the project — running commands,
 * writing files, reaching the network. This one appends a row to the project's own gitignored
 * database and nothing else, so prompting for it would train the user to approve reflexively,
 * which is how a real approval prompt stops being read.
 */
class MemoryTool implements Tool
{
    public function __construct(private readonly EventLog $events) {}

    public function name(): string
    {
        return 'remember';
    }

    public function description(): string
    {
        return 'Records a durable fact about this project that survives into later sessions, or '
            .'forgets one. Use it for things worth carrying forward — conventions, decisions, '
            .'where things live — not for the contents of this conversation.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'op' => ['type' => 'string', 'enum' => ['set', 'forget']],
                'key' => ['type' => 'string'],
                'value' => ['type' => 'string'],
            ],
            'required' => ['op', 'key'],
        ];
    }

    // ponytail: unused, only read/write/patch/git use the out-of-band signal — see Tool::execute() doc
    public function execute(array $input, bool $approved = false): ToolResult
    {
        $op = $input['op'] ?? null;
        $key = $input['key'] ?? null;

        if (! is_string($key) || trim($key) === '') {
            return ToolResult::fail('key must be a non-empty string');
        }

        $key = trim($key);

        if ($op === 'forget') {
            $this->events->append(MemoryStore::RETRACT, ['key' => $key]);

            return ToolResult::ok("forgot: {$key}");
        }

        if ($op !== 'set') {
            return ToolResult::fail("op must be 'set' or 'forget'");
        }

        $value = $input['value'] ?? null;

        if (! is_string($value) || trim($value) === '') {
            return ToolResult::fail('value must be a non-empty string when op is set');
        }

        // Bounded because every stored fact is re-sent in the system prompt on every turn of
        // every future session — an unbounded value is a permanent, recurring token cost, not
        // a one-off one.
        if (strlen($value) > MemoryStore::MAX_VALUE_BYTES) {
            return ToolResult::fail('value exceeds '.MemoryStore::MAX_VALUE_BYTES.' bytes — store a pointer, not the contents');
        }

        $this->events->append(MemoryStore::SET, ['key' => $key, 'value' => trim($value)]);

        return ToolResult::ok("remembered: {$key}");
    }
}
