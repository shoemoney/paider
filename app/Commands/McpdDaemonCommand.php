<?php

namespace App\Commands;

use LaravelZero\Framework\Commands\Command;
use Symfony\Component\Process\Process;

final class McpdDaemonCommand extends Command
{
    protected $signature = 'mcpd:daemon {--addr=127.0.0.1:8090 : HTTP listen address}';

    protected $description = 'Run Mozilla mcpd in the current project (requires the mcpd binary)';

    public function handle(): int
    {
        if (! is_file(getcwd().'/.mcpd.toml')) {
            $this->error('No .mcpd.toml found. Run mcpd init, then mcpd add <server>.');

            return self::FAILURE;
        }
        $process = new Process(['mcpd', 'daemon', '--addr', $this->option('addr')], getcwd());
        $process->setTimeout(null);
        $this->trap([SIGINT, SIGTERM], static function (int $signal) use ($process): void {
            if ($process->isRunning()) {
                $process->signal($signal);
            }
        });
        try {
            return $process->run(function (string $type, string $data): void {
                $this->output->write($data);
            });
        } finally {
            if ($process->isRunning()) {
                $process->stop(5);
            }
        }
    }
}
