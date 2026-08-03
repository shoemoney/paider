<?php

namespace App\Tools;

use App\Tools\Contracts\Tool;

/**
 * Not approval-gated: git operations on the user's own repo are lower-risk than
 * free-form shell per PLAN.md. Only ShellTool is gated.
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
            ],
            'required' => ['op'],
        ];
    }

    public function execute(array $input): ToolResult
    {
        return match ($input['op'] ?? null) {
            'diff' => $this->diff($input['staged'] ?? false),
            'add' => $this->add($input['path'] ?? '.'),
            'commit' => $this->commit($input['message'] ?? ''),
            default => ToolResult::fail('unknown op'),
        };
    }

    private function diff(bool $staged): ToolResult
    {
        $command = ['git', 'diff'];

        if ($staged) {
            $command[] = '--staged';
        }

        [$code, $stdout, $stderr] = $this->run($command);

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

        $process = proc_open($command, $descriptors, $pipes, $this->projectRoot);

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
