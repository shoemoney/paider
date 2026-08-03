<?php

namespace App\Tools;

use App\Support\PathGuard;
use App\Support\SecretsGuard;
use App\Tools\Contracts\Tool;

final class WriteFileTool implements Tool
{
    public function __construct(private readonly string $projectRoot) {}

    public function name(): string
    {
        return 'write_file';
    }

    public function description(): string
    {
        return 'Writes a file within the project root, atomically.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'path' => ['type' => 'string'],
                'content' => ['type' => 'string'],
            ],
            'required' => ['path', 'content'],
        ];
    }

    public function execute(array $input, bool $approved = false): ToolResult
    {
        $path = $input['path'];
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

        $content = $input['content'];
        $dir = dirname($absolute);

        if (! is_dir($dir) && ! mkdir($dir, 0755, recursive: true) && ! is_dir($dir)) {
            return ToolResult::fail('failed to create parent directory');
        }

        $tmp = $absolute.'.paider-tmp-'.bin2hex(random_bytes(4));

        try {
            if (file_put_contents($tmp, $content) === false) {
                return ToolResult::fail('failed to write temp file');
            }

            if (! rename($tmp, $absolute)) {
                return ToolResult::fail('failed to finalize write');
            }
        } finally {
            if (is_file($tmp)) {
                unlink($tmp);
            }
        }

        $bytes = strlen($content);

        return ToolResult::ok("wrote {$bytes} bytes", ['bytes' => $bytes]);
    }
}
