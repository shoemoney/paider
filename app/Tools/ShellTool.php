<?php

namespace App\Tools;

use App\Support\ShellEnv;
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

    // ponytail: unused, only read/write/patch/git use the out-of-band signal — see Tool::execute() doc
    public function execute(array $input, bool $approved = false): ToolResult
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

        $process = proc_open($input['command'], $descriptors, $pipes, $this->projectRoot, ShellEnv::build());

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
                // ponytail: pcntl/posix are LOCKED off the build (EXTENSIONS.md — cut for a
                // single-profile, Windows-compatible binary), so no posix_setsid/posix_kill and
                // no process-group kill (proc_open shares this process's own group, so a group
                // kill would take PHP down with it). Instead: snapshot the whole descendant tree
                // BEFORE signalling anything, then SIGTERM/SIGKILL every pid in it directly via
                // the `kill` binary — ext-standard's exec(), not the posix extension. Known
                // ceiling: this only reaches descendants still in the tracked pid's process tree
                // AT THE DEADLINE. A child that has already double-forked or been backgrounded
                // and reparented to init BEFORE the deadline fires (e.g. `( cmd & )`, `sh -c
                // 'cmd &'`) is gone from the tree before we ever snapshot it and will keep
                // running for the rest of its life — the whole timeout window is the exposure,
                // not a few milliseconds of race.
                $descendants = $this->descendantPids(proc_get_status($process)['pid']);

                proc_terminate($process); // SIGTERM — a well-behaved child dies here
                $this->killPids($descendants, 15);

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

                // Descendants aren't PHP's direct children, so proc_close never reaps them —
                // give any that trapped TERM the same TERM-then-KILL treatment before returning.
                $this->killPids($descendants, 15);
                usleep(100_000);
                $this->killPids($descendants, 9);

                fclose($pipes[1]);
                fclose($pipes[2]);
                proc_close($process);

                return ToolResult::fail('command timed out (detached children may still be running)', ['timed_out' => true]);
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

    /**
     * Every live pid descended from $pid, walked via a single `ps` snapshot (BSD and GNU `ps`
     * both understand `-A -o pid=,ppid=`). No pcntl/posix required — see the ponytail note above.
     *
     * @return int[]
     */
    private function descendantPids(int $pid): array
    {
        $childrenOf = [];

        foreach (explode("\n", trim((string) shell_exec('ps -A -o pid=,ppid= 2>/dev/null'))) as $line) {
            if (! preg_match('/^\s*(\d+)\s+(\d+)\s*$/', $line, $m)) {
                continue;
            }

            $childrenOf[(int) $m[2]][] = (int) $m[1];
        }

        $descendants = [];
        $queue = $childrenOf[$pid] ?? [];

        while ($queue !== []) {
            $current = array_pop($queue);
            $descendants[] = $current;
            array_push($queue, ...($childrenOf[$current] ?? []));
        }

        return $descendants;
    }

    /** @param int[] $pids */
    private function killPids(array $pids, int $signal): void
    {
        foreach ($pids as $pid) {
            @exec('kill -'.$signal.' '.escapeshellarg((string) $pid).' 2>/dev/null');
        }
    }
}
