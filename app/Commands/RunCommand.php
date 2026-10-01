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
    protected $signature = 'run {prompt? : Prompt to send non-interactively} {--y|yes : Auto-approve without prompting} {--yolo : Alias for --yes} {--require-edit : Fail if no write_file/patch_file tool call succeeded this run}';

    protected $description = 'Run a single non-interactive turn (CI mode). Auto-approves when --yes/--yolo is set';

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

        // Check if last tool_call failed — exit non-zero for CI.
        //
        // lastOf() answers this as one indexed DESC LIMIT 1 row, scoped to THIS session. It used
        // to be all() + array_reverse(), which decoded the ENTIRE history into memory to read
        // one event off the end — scaling with total history rather than with the question.
        //
        // The session scope is not optional. EventLog persists across runs in
        // .paider/paider.db, so an UNSCOPED "last failing tool_call" is a previous run's verdict
        // wearing this run's exit code: a CI job that asked a question, made no tool calls, and
        // still went red because the last thing that happened last week failed. The method
        // immediately below this one already argues exactly that point in its docblock.
        $last = $eventLog->lastOf(['tool_call', 'test_run'], $eventLog->sessionId());

        if ($last !== null && isset($last['payload']['ok']) && $last['payload']['ok'] === false) {
            return self::FAILURE;
        }

        if ($this->option('require-edit') && ! $this->sessionLandedAnEdit($eventLog)) {
            $this->error('--require-edit: no write_file/patch_file tool call succeeded this run — nothing was edited.');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * Scoped to THIS run's session_id, not just "the last matching event" — EventLog persists
     * across sessions in .paider/paider.db, so a prior session's successful write must not
     * satisfy --require-edit for a run that landed no edit of its own.
     *
     * Streams and returns on the first match. Passing the whole log in was the other half of
     * the memory problem above, and it was avoidable: the answer is a boolean that usually
     * resolves in the first few rows of a fresh session, so nothing after the first match is
     * ever read.
     */
    private function sessionLandedAnEdit(EventLog $eventLog): bool
    {
        $sessionId = $eventLog->sessionId();

        foreach ($eventLog->stream() as $event) {
            $payload = $event['payload'];
            if ($event['type'] === 'tool_call'
                && ($payload['session_id'] ?? null) === $sessionId
                && ($payload['ok'] ?? false) === true
                && in_array($payload['tool'] ?? null, ['write_file', 'patch_file'], true)
            ) {
                return true;
            }
        }

        return false;
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

    /**
     * protected, not private: FakeRunCommand (tests/Feature/RunCommandTest.php) overrides this
     * to inject a QueuedProviderClient — same seam CommitCommand::providerClient() uses — since
     * the real ProviderResolver would otherwise require a live API key.
     */
    protected function resolveProvider(): ProviderClient
    {
        return ProviderResolver::forPreset(SettingsStore::activePreset());
    }
}
