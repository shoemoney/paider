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
        $path = $input['path'] ?? null;
        $content = $input['content'] ?? null;

        // Root-cause guard, matching PatchFileTool: a malformed tool call (missing/wrong-type
        // key) must not fatal — undefined-key access here either kills the whole chat session
        // or, worse, writes 0 bytes over a real file before the framework's error handler stops
        // it. Fail closed and let the model retry with a valid call.
        if (! is_string($path) || $path === '' || ! is_string($content)) {
            return ToolResult::fail('path and content are required strings', ['invalid_input' => true]);
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
