<?php

namespace App\Tools;

use App\Tools\Contracts\Tool;

/**
 * UI-agnostic: this tool does not own an Approval\Gate or prompt anyone itself. The caller
 * (the agent loop) resolves the approval decision and passes it in as $input['approval'].
 */
class ShellTool implements Tool
{
    public function __construct(
        private readonly string $projectRoot,
        private readonly int $timeoutSeconds = 60,
    ) {}

    public function name(): string
    {
        return 'run_shell';
    }

    public function description(): string
    {
        return 'Runs a shell command in the project root. Requires an approval decision.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'command' => ['type' => 'string'],
                'approval' => ['type' => 'string', 'enum' => ['allow-once', 'allow-session', 'deny']],
            ],
            'required' => ['command'],
        ];
    }

    public function execute(array $input): ToolResult
    {
        // proc_open() also accepts an argv-array command (no shell, execve directly) — this
        // tool only advertises a string in inputSchema(), so anything else is malformed
        // input, not a command to run. Root-cause guard: covers every caller, not just Loop.
        if (! is_string($input['command'] ?? null) || $input['command'] === '') {
            return ToolResult::fail('command must be a string');
        }

        if (! array_key_exists('approval', $input)) {
            return ToolResult::fail('approval required', ['needs_approval' => true]);
        }

        if ($input['approval'] === 'deny') {
            return ToolResult::fail('denied');
        }

        if (! in_array($input['approval'], ['allow-once', 'allow-session'], true)) {
            return ToolResult::fail('approval required', ['needs_approval' => true]);
        }

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open($input['command'], $descriptors, $pipes, $this->projectRoot);

        if (! is_resource($process)) {
            return ToolResult::fail('failed to start command');
        }

        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $stdout = '';
        $stderr = '';
        $deadline = microtime(true) + $this->timeoutSeconds;

        while (true) {
            $stdout .= stream_get_contents($pipes[1]);
            $stderr .= stream_get_contents($pipes[2]);

            $status = proc_get_status($process);

            if (! $status['running']) {
                break;
            }

            if (microtime(true) >= $deadline) {
                // ponytail: SIGTERM/SIGKILL reach only the tracked pid. Anything the command
                // detached (`&`, `( ... &)`) survives, reparented to init. proc_open gives the
                // child PHP's own process group, so a group kill would take this process down
                // with it; a real fix needs posix_setsid/pcntl_fork, both out of scope (LOCKED:
                // no pcntl/posix). Bounded, not fully containing — see the return below.
                proc_terminate($process); // SIGTERM — a well-behaved child dies here

                $killDeadline = microtime(true) + 0.5;
                while (proc_get_status($process)['running'] && microtime(true) < $killDeadline) {
                    usleep(20_000);
                }

                if (proc_get_status($process)['running']) {
                    proc_terminate($process, 9); // SIGKILL — cannot be trapped or ignored

                    $reapDeadline = microtime(true) + 2.0;
                    while (proc_get_status($process)['running'] && microtime(true) < $reapDeadline) {
                        usleep(20_000);
                    }
                }

                fclose($pipes[1]);
                fclose($pipes[2]);
                proc_close($process);

                return ToolResult::fail('command timed out (any detached children may still be running)', ['timed_out' => true]);
            }

            usleep(20_000);
        }

        $stdout .= stream_get_contents($pipes[1]);
        $stderr .= stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($process);

        if ($code === 0) {
            return ToolResult::ok($stdout, ['exit_code' => $code, 'stderr' => $stderr]);
        }

        return ToolResult::fail($stderr !== '' ? $stderr : $stdout, ['exit_code' => $code]);
    }
}
