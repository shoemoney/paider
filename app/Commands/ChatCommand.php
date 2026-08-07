<?php

namespace App\Commands;

use App\Agent\Loop;
use App\Agent\Session;
use App\Agent\TierRouter;
use App\Approval\Gate;
use App\Providers\AnthropicClient;
use App\Providers\Contracts\ProviderClient;
use App\Providers\OpenAiCompatibleClient;
use App\Skills\SkillLibrary;
use App\Storage\Database;
use App\Storage\EventLog;
use App\Storage\SessionStore;
use App\Support\Banner;
use App\Support\ChatPrompt;
use App\Support\ColorRole;
use App\Support\Palette;
use App\Support\SettingsStore;
use App\Support\TerminalSafe;
use App\Tools\ArtisanTool;
use App\Tools\Contracts\Tool;
use App\Tools\FetchUrlTool;
use App\Tools\GitTool;
use App\Tools\LoadSkillTool;
use App\Tools\MemoryTool;
use App\Tools\PatchFileTool;
use App\Tools\ReadFileTool;
use App\Tools\ShellTool;
use App\Tools\WriteFileTool;
use LaravelZero\Framework\Commands\Command;

use function Laravel\Prompts\select;

class ChatCommand extends Command
{
    protected $signature = 'chat {--y|yolo : Approve every action without asking. Still cannot leave the project root or reach a private address.}';

    protected $description = 'Start an interactive Paider chat session in the current project.';

    private readonly string $projectRoot;

    private readonly ReadFileTool $readFileTool;

    private readonly GitTool $gitTool;

    private bool $quitRequested = false;

    private ?EventLog $eventLog = null;

    public function __construct()
    {
        parent::__construct();

        $this->projectRoot = getcwd();
        $this->readFileTool = new ReadFileTool($this->projectRoot);
        $this->gitTool = new GitTool($this->projectRoot);
    }

    public function handle(): int
    {
        $this->eventLog = new EventLog(Database::connect());
        $session = new Session($this->readFileTool, $this->projectRoot);

        // Index built once and reused for both the tool registration decision and the message
        // Loop injects — a user with zero skills under ~/.paider/skills pays for neither the
        // load_skill tool doc nor an empty catalogue in the prompt.
        $skillIndex = SkillLibrary::index();
        $tools = $this->buildTools($skillIndex);

        $gate = Gate::forSession((bool) $this->option('yolo'));

        $loop = new Loop(
            $tools,
            $this->resolveProvider(),
            new TierRouter,
            $this->eventLog,
            $gate,
            skillIndex: $skillIndex,
        );

        echo Banner::render();

        // Announced, never silent. A session that has stopped asking must say so, or the next
        // unprompted `rm -rf` reads as a bug rather than as the setting the user chose — and
        // PAIDER_YOLO in a shell profile is a setting it is very easy to forget you set.
        if ($gate->autoApproves()) {
            // Alert is bg+fg inverse video, but the words "YOLO" / "approving everything" carry
            // the meaning on their own — NO_COLOR must not make this badge silent, only plain.
            Palette::render(sprintf(
                '<div class="mb-1"><span class="px-1 %s">YOLO</span>'
                .'<span class="%s ml-1">approving everything%s — writes and commands run unprompted</span></div>',
                Palette::tw(ColorRole::Alert),
                Palette::tw(ColorRole::Muted),
                $this->option('yolo') ? '' : ' via '.Gate::ENV_VAR,
            ));
        }

        // Mandatory, not cosmetic: a project shipping its own .paider/skills or .claude/skills
        // is refused unconditionally (SkillLibrary's trust-boundary doc), and the user must be
        // told that happened rather than silently believe their project skills loaded.
        $skillsNotice = SkillLibrary::refusedProjectSkillsNotice();

        if ($skillsNotice !== null) {
            Palette::render(sprintf(
                '<div class="mb-1 %s">%s</div>',
                Palette::tw(ColorRole::Muted),
                htmlspecialchars($skillsNotice, ENT_QUOTES),
            ));
        }

        Palette::render(sprintf(
            '<div class="mb-1">
                <span class="%1$s">type </span><span class="%2$s">/quit</span><span class="%1$s"> to exit</span>
            </div>',
            Palette::tw(ColorRole::Muted),
            Palette::tw(ColorRole::Accent),
        ));

        $this->resume($session);

        while (! $this->quitRequested) {
            // ChatPrompt, not text()/textarea() — see that class for why neither stock
            // prompt works here.
            $line = ChatPrompt::ask('paider>');

            if ($this->handleSlashCommand($session, $line)) {
                continue;
            }

            $loop->turn($session, $line, fn (string $subject) => $this->promptApproval($subject));
        }

        return self::SUCCESS;
    }

    /**
     * The tool roster for this session — pulled out of handle() so the composition-root wiring
     * (does load_skill actually get registered when there are skills to load?) is reachable
     * from a test without standing up a live REPL, an EventLog, or a real ProviderClient. This
     * is the exact seam a prior review found completely untested: a mutated `if (false) {
     * $tools[] = new LoadSkillTool; }` here left the whole suite green.
     *
     * @param  array<int, array{name: string, description: string}>  $skillIndex
     * @return array<int, Tool>
     */
    private function buildTools(array $skillIndex): array
    {
        $tools = [
            $this->readFileTool,
            new WriteFileTool($this->projectRoot),
            new PatchFileTool($this->projectRoot),
            new ShellTool($this->projectRoot),
            new FetchUrlTool,
            new MemoryTool($this->eventLog),
            $this->gitTool,
        ];

        if (file_exists($this->projectRoot.'/artisan')) {
            $tools[] = new ArtisanTool($this->projectRoot);
        }

        if ($skillIndex !== []) {
            $tools[] = new LoadSkillTool;
        }

        return $tools;
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
                $this->eventLog?->append(SessionStore::FILE_DROPPED, ['path' => $rest]);

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

        // Recorded only on success: a path that failed to read is not in context, and
        // replaying it later would re-fail on every resume for the rest of the project's life.
        if ($result->ok) {
            $this->eventLog?->append(SessionStore::FILE_ADDED, ['path' => $path]);
        }

        Palette::render($result->ok
            ? "<div class=\"ml-2\">added {$path}</div>"
            : sprintf('<div class="ml-2 %s">%s</div>', Palette::tw(ColorRole::Error), $result->output));
    }

    /**
     * Rebuild the previous conversation before the first turn. Runs after the banner so the
     * user is told what was restored rather than silently starting mid-conversation with a
     * context they can no longer see.
     */
    private function resume(Session $session): void
    {
        if ($this->eventLog === null) {
            return;
        }

        $store = new SessionStore($this->eventLog);
        $messages = $store->messages();

        if ($messages === []) {
            return;
        }

        $session->replay($messages);

        // Files are re-read from disk rather than restored from the log, so a file edited
        // since the last run comes back current — and its sha256 stamp describes what is
        // actually there, which is the only way a later patch can apply.
        $files = 0;

        foreach ($store->contextFiles() as $path) {
            if ($session->addFile($path)->ok) {
                $files++;
            }
        }

        Palette::render(sprintf(
            '<div class="mb-1"><span class="%s">resumed</span>'
            .'<span class="%s ml-1">%d message%s%s — /quit does not clear it</span></div>',
            Palette::tw(ColorRole::Accent),
            Palette::tw(ColorRole::Muted),
            count($messages),
            count($messages) === 1 ? '' : 's',
            $files > 0 ? sprintf(', %d file%s', $files, $files === 1 ? '' : 's') : '',
        ));
    }

    private function handleDiff(): void
    {
        $result = $this->gitTool->execute(['op' => 'diff']);

        echo self::colorizeDiff(TerminalSafe::clean($result->output)).PHP_EOL;
    }

    /**
     * Structural colour only — which lines were added/removed/where a hunk starts — not
     * per-hunk syntax highlighting. That needs a diff parser plus extension-to-language
     * mapping plus old/new alignment; prefix colour solves the actual readability problem
     * ("which lines changed") with zero new dependencies, so it's the deliberate stopping
     * point here, not an oversight.
     *
     * '+++'/'---' file headers are checked BEFORE the bare +/- prefixes below — the classic
     * off-by-one in prefix-based diff colouring, since every '+++' line also starts with '+'.
     *
     * That header check only applies BEFORE the first hunk of each file, tracked via $inHunk:
     * a real '---'/'+++' pair only ever appears between a 'diff --git' line and that file's
     * first '@@' line. Once a hunk has started, a line that happens to start with '--' or '++'
     * is deleted/added CONTENT (a SQL comment, a YAML/markdown separator, C '--x') — colouring
     * it Muted instead of Error/Success would make a reviewer lose real diff lines.
     */
    private static function colorizeDiff(string $diff): string
    {
        $lines = explode("\n", $diff);
        $inHunk = false;

        foreach ($lines as &$line) {
            if (str_starts_with($line, 'diff --git')) {
                $inHunk = false;
            } elseif (str_starts_with($line, '@@')) {
                $inHunk = true;
            }

            $line = match (true) {
                ! $inHunk && (str_starts_with($line, '+++') || str_starts_with($line, '---')) => Palette::wrap(ColorRole::Muted, $line),
                str_starts_with($line, '@@') => Palette::wrap(ColorRole::Accent, $line),
                str_starts_with($line, '+') => Palette::wrap(ColorRole::Success, $line),
                str_starts_with($line, '-') => Palette::wrap(ColorRole::Error, $line),
                default => $line,
            };
        }

        return implode("\n", $lines);
    }

    private function handleUndo(Session $session): void
    {
        $result = $session->undo();

        Palette::render(match ($result['status']) {
            'ok' => "<div class=\"ml-2\">reverted {$result['path']}</div>",
            'empty' => '<div class="ml-2">nothing to undo</div>',
            'conflict' => sprintf(
                '<div class="ml-2 %s">conflict — %s changed since the apply, restoring nothing</div>',
                Palette::tw(ColorRole::Error),
                $result['path'],
            ),
        });
    }

    private function handleTier(Session $session, string $args): void
    {
        [$tier, $model] = array_pad(explode(' ', $args, 2), 2, '');

        if ($tier === '' || $model === '') {
            Palette::render(sprintf(
                '<div class="ml-2 %s">usage: /tier &lt;name&gt; &lt;model&gt;</div>',
                Palette::tw(ColorRole::Error),
            ));

            return;
        }

        try {
            $session->setTierOverride($tier, $model);
        } catch (\InvalidArgumentException $e) {
            Palette::render(sprintf('<div class="ml-2 %s">%s</div>', Palette::tw(ColorRole::Error), $e->getMessage()));

            return;
        }

        Palette::render("<div class=\"ml-2\">{$tier} → {$model} for this session</div>");
    }

    private function promptApproval(string $subject): string
    {
        // $subject is model-controlled (dispatchShell/dispatchFetch pass $input['command']/
        // $input['url'] verbatim from the parsed reply) — a prompt injection with an embedded
        // erase-line sequence could otherwise repaint this label after the human reads it,
        // making them approve a different command than the one that actually runs.
        return select(
            label: 'Approve: '.TerminalSafe::clean($subject).'?',
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
