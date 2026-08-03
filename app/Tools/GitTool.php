<?php

namespace App\Tools;

use App\Support\SecretsGuard;
use App\Support\ShellEnv;
use App\Tools\Contracts\Tool;

/**
 * add/commit are not approval-gated: metadata operations on the user's own repo are
 * lower-risk than free-form shell per PLAN.md. diff is a content-disclosure operation
 * (like ReadFileTool), so it is gated on SecretsGuard the same way.
 */
class GitTool implements Tool
{
    public function __construct(
        private readonly string $projectRoot,
    ) {}

    public function name(): string
    {
        return 'git';
    }

    public function description(): string
    {
        return 'Runs a scoped git operation (diff, add, commit) against the project repo.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'op' => ['type' => 'string', 'enum' => ['diff', 'add', 'commit']],
                'staged' => ['type' => 'boolean'],
                'path' => ['type' => 'string'],
                'message' => ['type' => 'string'],
                'approved' => ['type' => 'boolean'],
            ],
            'required' => ['op'],
        ];
    }

    public function execute(array $input): ToolResult
    {
        return match ($input['op'] ?? null) {
            'diff' => $this->diff($input['staged'] ?? false, $input['approved'] ?? false),
            'add' => $this->add($input['path'] ?? '.'),
            'commit' => $this->commit($input['message'] ?? ''),
            default => ToolResult::fail('unknown op'),
        };
    }

    private function diff(bool $staged, bool $approved): ToolResult
    {
        $flags = $staged ? ['--staged'] : [];

        [$nameCode, $nameStdout] = $this->run(['git', 'diff', ...$flags, '--name-only']);

        if ($nameCode === 0) {
            foreach (preg_split('/\r?\n/', trim($nameStdout)) as $relative) {
                if ($relative === '') {
                    continue;
                }

                if (SecretsGuard::isSensitive($this->projectRoot.'/'.$relative) && ! $approved) {
                    return ToolResult::fail('diff includes a sensitive path: '.$relative, [
                        'needs_approval' => true,
                        'reason' => 'secrets',
                    ]);
                }
            }
        }

        [$code, $stdout, $stderr] = $this->run(['git', 'diff', ...$flags]);

        return $code === 0 ? ToolResult::ok($stdout) : ToolResult::fail($stderr);
    }

    private function add(string $path): ToolResult
    {
        [$code, $stdout, $stderr] = $this->run(['git', 'add', '--', $path]);

        return $code === 0 ? ToolResult::ok($stdout) : ToolResult::fail($stderr);
    }

    private function commit(string $message): ToolResult
    {
        if ($message === '') {
            return ToolResult::fail('empty commit message');
        }

        [$code, $stdout, $stderr] = $this->run(['git', 'commit', '-m', $message]);

        return $code === 0 ? ToolResult::ok($stdout) : ToolResult::fail($stderr);
    }

    /**
     * @param  list<string>  $command
     * @return array{0: int, 1: string, 2: string}
     */
    private function run(array $command): array
    {
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open($command, $descriptors, $pipes, $this->projectRoot, ShellEnv::build());

        if (! is_resource($process)) {
            return [1, '', 'failed to start git'];
        }

        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($process);

        return [$code, $stdout, $stderr];
    }
}
