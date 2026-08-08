<?php

namespace App\Commands;

use App\Agent\Loop;
use App\Agent\Session;
use App\Agent\TierRouter;
use App\Approval\Gate;
use App\Providers\Contracts\ProviderClient;
use App\Providers\McpClient;
use App\Providers\ProviderResolver;
use App\Skills\SkillLibrary;
use App\Storage\Database;
use App\Storage\EventLog;
use App\Support\SettingsStore;
use App\Tools\ArtisanTool;
use App\Tools\FetchUrlTool;
use App\Tools\GitTool;
use App\Tools\LoadSkillTool;
use App\Tools\MemoryTool;
use App\Tools\PatchFileTool;
use App\Tools\ReadFileTool;
use App\Tools\ShellTool;
use App\Tools\WriteFileTool;
use Illuminate\Console\Command;

class RunCommand extends Command
{
    protected $signature = 'run {prompt? : Prompt to send non-interactively} {--y|yes : Auto-approve without prompting} {--yolo : Alias for --yes}';

    protected $description = 'Run a single non-interactive turn (CI mode). Auto-approves when --yes/--yolo is set.';

    private readonly string $projectRoot;

    private readonly ReadFileTool $readFileTool;

    private readonly GitTool $gitTool;

    public function __construct()
    {
        parent::__construct();
        $this->projectRoot = (string) getcwd();
        $this->readFileTool = new ReadFileTool($this->projectRoot);
        $this->gitTool = new GitTool($this->projectRoot);
    }

    public function handle(): int
    {
        $prompt = $this->argument('prompt');

        // Support `paider run --yes "prompt"` where prompt may be next arg after option parsing
        if (! is_string($prompt) || trim($prompt) === '') {
            $this->error('No prompt provided. Usage: paider run --yes "your prompt here"');

            return self::FAILURE;
        }

        $autoApprove = (bool) ($this->option('yes') || $this->option('yolo'));

        $eventLog = new EventLog(Database::connect());
        $session = new Session($this->readFileTool, $this->projectRoot);

        $skillIndex = SkillLibrary::index();
        $tools = $this->buildTools($skillIndex, $eventLog);

        $gate = Gate::forSession($autoApprove);

        // If --yes was passed, announce it (same fidelity as ChatCommand)
        if ($gate->autoApproves() && $autoApprove) {
            $this->warn('YOLO — approving everything (run --yes)');
        }

        $loop = new Loop(
            $tools,
            $this->resolveProvider(),
            new TierRouter,
            $eventLog,
            $gate,
            skillIndex: $skillIndex,
            projectRoot: $this->projectRoot,
        );

        try {
            $loop->turn($session, $prompt, function (string $subject) use ($gate): string {
                // In --yes mode, Gate already returns true and this is never called.
                // Without --yes, fall back to deny for non-interactive.
                return $gate->autoApproves() ? 'allow-once' : 'deny';
            });
        } catch (\Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        // Check if last tool_call failed — exit non-zero for CI
        $events = $eventLog->all();
        foreach (array_reverse($events) as $event) {
            if ($event['type'] === 'tool_call') {
                $payload = json_decode($event['payload'], true);
                if (isset($payload['ok']) && $payload['ok'] === false) {
                    return self::FAILURE;
                }
                break;
            }
            // Also check test_run failures
            if ($event['type'] === 'test_run') {
                $payload = json_decode($event['payload'], true);
                if (isset($payload['ok']) && $payload['ok'] === false) {
                    return self::FAILURE;
                }
                break;
            }
        }

        return self::SUCCESS;
    }

    /** @param array<int, array{name: string, description: string}> $skillIndex */
    private function buildTools(array $skillIndex, EventLog $eventLog): array
    {
        $tools = [
            $this->readFileTool,
            new WriteFileTool($this->projectRoot),
            new PatchFileTool($this->projectRoot),
            new ShellTool($this->projectRoot),
            new FetchUrlTool,
            new MemoryTool($eventLog),
            $this->gitTool,
        ];

        if (file_exists($this->projectRoot.'/artisan')) {
            $tools[] = new ArtisanTool($this->projectRoot);
        }

        if ($skillIndex !== []) {
            $tools[] = new LoadSkillTool;
        }

        foreach (McpClient::tools($this->projectRoot) as $mcpTool) {
            $tools[] = $mcpTool;
        }

        return $tools;
    }

    private function resolveProvider(): ProviderClient
    {
        return ProviderResolver::forPreset(SettingsStore::activePreset());
    }
}
