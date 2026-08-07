<?php

namespace App\Tools;

use App\Support\PathGuard;
use App\Support\SecretsGuard;
use App\Tools\Contracts\Tool;

final class ReadFileTool implements Tool
{
    public function __construct(private readonly string $projectRoot) {}

    public function name(): string
    {
        return 'read_file';
    }

    public function description(): string
    {
        return 'Reads a file within the project root.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'path' => ['type' => 'string'],
            ],
            'required' => ['path'],
        ];
    }

    public function execute(array $input, bool $approved = false): ToolResult
    {
        $path = $input['path'] ?? null;

        // Root-cause guard, matching PatchFileTool: a non-string path throws a TypeError out of
        // str_starts_with() that no error handler intercepts, killing the chat session.
        if (! is_string($path) || $path === '') {
            return ToolResult::fail('path is a required string', ['invalid_input' => true]);
        }

        $absolute = str_starts_with($path, '/') ? $path : $this->projectRoot.'/'.$path;

        if (! PathGuard::containedIn($this->projectRoot, $absolute)) {
            return ToolResult::fail('path escapes project root');
        }

        if (SecretsGuard::isSensitive($absolute) && ! $approved) {
            return ToolResult::fail('sensitive path requires approval', [
                'needs_approval' => true,
                'reason' => 'secrets',
            ]);
        }

        if (! is_file($absolute) || ! is_readable($absolute)) {
            return ToolResult::fail('file not found or not readable');
        }

        $contents = file_get_contents($absolute);

        if ($contents === false) {
            return ToolResult::fail('failed to read file');
        }

        return ToolResult::ok($contents);
    }
}
