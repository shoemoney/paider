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
                'approved' => ['type' => 'boolean'],
            ],
            'required' => ['path'],
        ];
    }

    public function execute(array $input): ToolResult
    {
        $path = $input['path'];
        $absolute = str_starts_with($path, '/') ? $path : $this->projectRoot.'/'.$path;

        if (! PathGuard::containedIn($this->projectRoot, $absolute)) {
            return ToolResult::fail('path escapes project root');
        }

        if (SecretsGuard::isSensitive($absolute) && ! ($input['approved'] ?? false)) {
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
