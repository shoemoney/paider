<?php

namespace App\Commands;

use App\Agent\Loop;
use App\Agent\Session;
use App\Agent\TierRouter;
use App\Approval\Gate;
use App\Providers\AnthropicClient;
use App\Providers\Contracts\ProviderClient;
use App\Providers\OpenAiCompatibleClient;
use App\Storage\Database;
use App\Storage\EventLog;
use App\Support\Banner;
use App\Support\SettingsStore;
use App\Tools\ArtisanTool;
use App\Tools\GitTool;
use App\Tools\PatchFileTool;
use App\Tools\ReadFileTool;
use App\Tools\ShellTool;
use App\Tools\WriteFileTool;
use LaravelZero\Framework\Commands\Command;

use function Laravel\Prompts\select;
use function Laravel\Prompts\textarea;
use function Termwind\render;

class ChatCommand extends Command
{
    protected $signature = 'chat';

    protected $description = 'Start an interactive Paider chat session in the current project.';

    private readonly string $projectRoot;

    private readonly ReadFileTool $readFileTool;

    private readonly GitTool $gitTool;

    private bool $quitRequested = false;

    public function __construct()
    {
        parent::__construct();

        $this->projectRoot = getcwd();
        $this->readFileTool = new ReadFileTool($this->projectRoot);
        $this->gitTool = new GitTool($this->projectRoot);
    }

    public function handle(): int
    {
        $session = new Session($this->readFileTool, $this->projectRoot);

        $tools = [
            $this->readFileTool,
            new WriteFileTool($this->projectRoot),
            new PatchFileTool($this->projectRoot),
            new ShellTool($this->projectRoot),
            $this->gitTool,
        ];

        if (file_exists($this->projectRoot.'/artisan')) {
            $tools[] = new ArtisanTool($this->projectRoot);
        }

        $loop = new Loop(
            $tools,
            $this->resolveProvider(),
            new TierRouter,
            new EventLog(Database::connect()),
            new Gate,
        );

        echo Banner::render();

        render(<<<'HTML'
            <div class="mb-1">
                <span class="text-gray">type </span><span class="text-cyan">/quit</span><span class="text-gray"> to exit</span>
            </div>
        HTML);

        while (! $this->quitRequested) {
            // textarea, not text: text() is a single line that scrolls horizontally and elides
            // the overflow behind a '…', so a long prompt becomes unreadable while typing it.
            // The trade is that Enter inserts a newline and Ctrl+D sends — the renderer prints
            // that hint itself, so it needs no extra label here.
            $line = textarea('paider>', rows: 3);

            if ($this->handleSlashCommand($session, $line)) {
                continue;
            }

            $loop->turn($session, $line, fn (string $subject) => $this->promptApproval($subject));
        }

        return self::SUCCESS;
    }

    /**
     * Returns true when $line was a recognized slash command (handled here, not forwarded
     * to Loop::turn). Deliberately touches nothing that requires a live REPL/stdin, so it's
     * directly callable from a test with a real Session and no model calls.
     */
    public function handleSlashCommand(Session $session, string $line): bool
    {
        $line = trim($line);

        if ($line === '' || $line[0] !== '/') {
            return false;
        }

        [$command, $rest] = array_pad(explode(' ', $line, 2), 2, '');
        $rest = trim($rest);

        switch ($command) {
            case '/add':
                $this->handleAdd($session, $rest);

                return true;
            case '/drop':
                $session->dropFile($rest);

                return true;
            case '/diff':
                $this->handleDiff();

                return true;
            case '/undo':
                $this->handleUndo($session);

                return true;
            case '/tier':
                $this->handleTier($session, $rest);

                return true;
            case '/quit':
                $this->quitRequested = true;

                return true;
            default:
                return false;
        }
    }

    public function shouldQuit(): bool
    {
        return $this->quitRequested;
    }

    private function handleAdd(Session $session, string $path): void
    {
        $result = $session->addFile($path);

        render($result->ok
            ? "<div class=\"ml-2\">added {$path}</div>"
            : "<div class=\"ml-2 text-red-500\">{$result->output}</div>");
    }

    private function handleDiff(): void
    {
        $result = $this->gitTool->execute(['op' => 'diff']);

        echo $result->output.PHP_EOL;
    }

    private function handleUndo(Session $session): void
    {
        $result = $session->undo();

        render(match ($result['status']) {
            'ok' => "<div class=\"ml-2\">reverted {$result['path']}</div>",
            'empty' => '<div class="ml-2">nothing to undo</div>',
            'conflict' => "<div class=\"ml-2 text-red-500\">conflict — {$result['path']} changed since the apply, restoring nothing</div>",
        });
    }

    private function handleTier(Session $session, string $args): void
    {
        [$tier, $model] = array_pad(explode(' ', $args, 2), 2, '');

        if ($tier === '' || $model === '') {
            render('<div class="ml-2 text-red-500">usage: /tier &lt;name&gt; &lt;model&gt;</div>');

            return;
        }

        try {
            $session->setTierOverride($tier, $model);
        } catch (\InvalidArgumentException $e) {
            render("<div class=\"ml-2 text-red-500\">{$e->getMessage()}</div>");

            return;
        }

        render("<div class=\"ml-2\">{$tier} → {$model} for this session</div>");
    }

    private function promptApproval(string $subject): string
    {
        return select(
            label: "Approve: {$subject}?",
            options: [
                'allow-once' => 'Allow once',
                'allow-session' => 'Allow for this session',
                'deny' => 'Deny',
            ],
        );
    }

    private function resolveProvider(): ProviderClient
    {
        // ponytail: every non-anthropic preset is OpenRouter-shaped per PLAN.md's Architecture
        // section — one base URL covers them, no per-preset client wiring needed yet.
        return SettingsStore::activePreset() === 'anthropic'
            ? new AnthropicClient
            : new OpenAiCompatibleClient('https://openrouter.ai/api/v1', 'OPENROUTER_API_KEY');
    }
}
