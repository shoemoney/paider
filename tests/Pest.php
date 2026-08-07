<?php

use App\Agent\Session;
use App\Providers\Contracts\ProviderClient;
use App\Providers\ProviderResponse;
use App\Tools\Contracts\Tool;
use App\Tools\ReadFileTool;
use App\Tools\ToolResult;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "uses()" function to bind a different classes or traits.
|
*/

uses(TestCase::class)->in('Feature');

/*
|--------------------------------------------------------------------------
| Shared Loop test doubles
|--------------------------------------------------------------------------
|
| Live here rather than inside LoopToolCallProtocolTest.php (their original home) because
| Pest.php is required for EVERY test run regardless of which files are targeted, while a file
| under Feature/ is only required when it — or the whole suite — is actually running. A
| targeted run like `pest tests/Feature/SkillToolEndToEndTest.php` used to fatal with "Class
| QueuedProviderClient not found" because these lived in a sibling file that never got loaded.
|
*/

/** Records exactly what it was dispatched with — never touches disk or a network. */
class RecordingTool implements Tool
{
    public int $callCount = 0;

    public ?array $lastInput = null;

    public function name(): string
    {
        return 'fake_tool';
    }

    public function description(): string
    {
        return 'test double, records dispatches';
    }

    public function inputSchema(): array
    {
        return ['type' => 'object'];
    }

    // ponytail: unused, only read/write/patch/git use the out-of-band signal — see Tool::execute() doc
    public function execute(array $input, bool $approved = false): ToolResult
    {
        $this->callCount++;
        $this->lastInput = $input;

        return ToolResult::ok('done');
    }
}

/** Records whether 'approval' was present in $input when Loop dispatched it — proves the gate ran first. */
class RecordingArtisanTool implements Tool
{
    public int $callCount = 0;

    public ?array $lastInput = null;

    public function name(): string
    {
        return 'artisan';
    }

    public function description(): string
    {
        return 'test double for the artisan tool';
    }

    public function inputSchema(): array
    {
        return ['type' => 'object'];
    }

    // ponytail: unused, only read/write/patch/git use the out-of-band signal — see Tool::execute() doc
    public function execute(array $input, bool $approved = false): ToolResult
    {
        $this->callCount++;
        $this->lastInput = $input;

        return ToolResult::ok('[]');
    }
}

/** Records whether 'approval' was present in $input when Loop dispatched it — proves the gate ran first, same as RecordingArtisanTool but for run_shell. */
class RecordingShellTool implements Tool
{
    public int $callCount = 0;

    public ?array $lastInput = null;

    public function name(): string
    {
        return 'run_shell';
    }

    public function description(): string
    {
        return 'test double for the shell tool';
    }

    public function inputSchema(): array
    {
        return ['type' => 'object'];
    }

    // ponytail: unused, only read/write/patch/git use the out-of-band signal — see Tool::execute() doc
    public function execute(array $input, bool $approved = false): ToolResult
    {
        $this->callCount++;
        $this->lastInput = $input;

        return ToolResult::ok('done');
    }
}

/** Replays a fixed queue of responses, repeating the last one if the loop asks for more. */
class QueuedProviderClient implements ProviderClient
{
    private int $cursor = 0;

    /** @var array<int, array<int, array{role: string, content: string}>> every it was sent */
    public array $seenMessages = [];

    /** @param array<int, ProviderResponse> $responses */
    public function __construct(private readonly array $responses) {}

    public function send(array $messages, string $model, array $options = []): ProviderResponse
    {
        $this->seenMessages[] = $messages;

        $response = $this->responses[$this->cursor] ?? end($this->responses);
        $this->cursor++;

        return $response;
    }
}

function loopTestSession(): Session
{
    return loopTestSessionWithRoot()[0];
}

/** @return array{0: Session, 1: string} */
function loopTestSessionWithRoot(): array
{
    // uniqid('', true) — plain uniqid() is microsecond-clock based and collides across
    // concurrent test processes (measured: two parallel `pest` runs hit `mkdir(): File
    // exists`). The extra entropy suffix makes that collision practically impossible.
    $root = sys_get_temp_dir().'/paider-loop-'.uniqid('', true);
    mkdir($root, recursive: true);
    $root = realpath($root);

    return [new Session(new ReadFileTool($root), $root), $root];
}

function neverApprove(): callable
{
    return function (string $subject): never {
        throw new RuntimeException("approval should not have been requested for: {$subject}");
    };
}

/*
|--------------------------------------------------------------------------
| Shared SkillLibrary test fixtures
|--------------------------------------------------------------------------
|
| Moved from SkillLibraryTest.php for the same reason as the Loop doubles above: reused by
| SkillToolEndToEndTest.php, and needed regardless of which single Feature file a targeted run
| actually requires.
|
*/

/** Points $HOME at a throwaway directory for the duration of $body, then restores it. */
function withFakeHome(Closure $body): mixed
{
    $home = sys_get_temp_dir().'/paider-skills-home-'.uniqid('', true);
    mkdir($home.'/'.'.paider/skills', recursive: true);

    $previous = getenv('HOME');
    putenv("HOME={$home}");

    try {
        return $body($home);
    } finally {
        putenv($previous === false ? 'HOME' : "HOME={$previous}");
    }
}

/** Writes a minimal, valid SKILL.md under $home/.paider/skills/$dirName. */
function writeSkill(string $home, string $dirName, string $name, string $description, string $body = '# Body'): void
{
    $dir = "{$home}/.paider/skills/{$dirName}";
    mkdir($dir, recursive: true);
    file_put_contents($dir.'/SKILL.md', "---\nname: {$name}\ndescription: {$description}\n---\n{$body}\n");
}

/** Stands the process inside a freshly-chdir'd project dir for the duration of $body. */
function inProjectDir(string $root, Closure $body): void
{
    mkdir($root, recursive: true);
    $previous = getcwd();
    chdir($root);

    try {
        $body();
    } finally {
        chdir($previous);
    }
}

/*
|--------------------------------------------------------------------------
| pty capture, retried
|--------------------------------------------------------------------------
|
| Tests that must exercise stream_isatty(STDOUT) === true allocate a real pty via script(1).
| That allocation competes for system resources, and under load the child gets starved: the
| capture comes back short or empty, the "did the probe run at all" markers are missing, and a
| SECURITY test reports red for a reason that has nothing to do with security. Observed twice —
| both times seconds after a heavy background job finished, and green on three consecutive idle
| runs either side.
|
| Retrying is the honest fix rather than a sleep. $marker is text the probe prints unconditionally,
| independent of the behaviour under test, so its absence means the harness never got going. If it
| is still missing after every attempt we return what we last saw and let the caller assert on it,
| so a genuinely broken probe still fails — this defers to load, it does not excuse a regression.
|
*/

function ptyCapture(string $phpCode, string $marker, int $attempts = 3): string
{
    $output = '';

    for ($attempt = 0; $attempt < $attempts; $attempt++) {
        $captureFile = tempnam(sys_get_temp_dir(), 'paider-tty-');

        shell_exec(
            'cd '.escapeshellarg(base_path()).' && '
            .'env -u NO_COLOR PAIDER_COLOR=0 script -q '.escapeshellarg($captureFile).' '
            .'php -r '.escapeshellarg($phpCode).' >/dev/null 2>&1'
        );

        $output = (string) file_get_contents($captureFile);
        unlink($captureFile);

        if (str_contains($output, $marker)) {
            return $output;
        }

        // Linear backoff. The contention that causes this is short-lived (another process
        // finishing), so a few tens of milliseconds is enough; a long sleep would just make a
        // real failure slow to report.
        usleep(50_000 * ($attempt + 1));
    }

    return $output;
}
