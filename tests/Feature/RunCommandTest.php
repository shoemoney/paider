<?php

use App\Commands\RunCommand;
use App\Providers\Contracts\ProviderClient;
use App\Providers\ProviderResponse;
use App\Storage\Database;
use App\Storage\EventLog;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;

/**
 * FakeRunCommand overrides the resolveProvider() seam (protected exactly for this) to inject a
 * QueuedProviderClient (tests/Pest.php) instead of hitting ProviderResolver — same pattern as
 * FakeCommitCommand in CommitCommandTest.php.
 */
class FakeRunCommand extends RunCommand
{
    public function __construct(private readonly ProviderClient $client)
    {
        parent::__construct();
    }

    protected function resolveProvider(): ProviderClient
    {
        return $this->client;
    }
}

/**
 * Driven the same way CommitCommandTest drives CommitCommand: straight through Symfony's own
 * run(), bypassing Artisan's container-cached command resolution (which would resolve the real
 * RunCommand before a test body ever gets a chance to swap in the fake).
 */
function runRun(RunCommand $command, $app, array $input = []): int
{
    $command->setLaravel($app);

    return $command->run(new ArrayInput($input), new NullOutput);
}

beforeEach(function () {
    $this->root = sys_get_temp_dir().DIRECTORY_SEPARATOR.'paider-run-'.uniqid('', true);
    mkdir($this->root, recursive: true);
    $this->root = realpath($this->root);
    $this->previousCwd = getcwd();
    chdir($this->root);
});

afterEach(function () {
    chdir($this->previousCwd);
});

test('happy path: mock provider drives read_file then a final reply, exits SUCCESS', function () {
    file_put_contents($this->root.'/notes.txt', 'hello from run command');

    $provider = new QueuedProviderClient([
        new ProviderResponse(
            content: "```tool\n".json_encode(['name' => 'read_file', 'input' => ['path' => 'notes.txt']])."\n```",
            tokensIn: 100,
            tokensOut: 20,
            raw: [],
        ),
        new ProviderResponse(content: 'Done — read notes.txt.', tokensIn: 50, tokensOut: 10, raw: []),
    ]);

    $exitCode = runRun(new FakeRunCommand($provider), $this->app, ['prompt' => 'what is in notes.txt?']);

    expect($exitCode)->toBe(0);

    $toolCalls = array_values(array_filter(
        (new EventLog(Database::connect()))->all(),
        fn ($event) => $event['type'] === 'tool_call'
    ));

    expect($toolCalls)->toHaveCount(1);
    expect($toolCalls[0]['payload']['tool'])->toBe('read_file');
    expect($toolCalls[0]['payload']['ok'])->toBeTrue();
});

test('no prompt argument fails with a usage error and never touches the provider', function () {
    $provider = new QueuedProviderClient([
        new ProviderResponse(content: 'unused', tokensIn: 0, tokensOut: 0, raw: []),
    ]);

    $exitCode = runRun(new FakeRunCommand($provider), $this->app, []);

    expect($exitCode)->toBe(1);
    expect($provider->seenMessages)->toBe([]);
});

test('without --yes/--yolo, a write-capable tool call (run_shell) is NOT auto-approved — the command never runs', function () {
    $proof = $this->root.'/PROOF';

    $provider = new QueuedProviderClient([
        new ProviderResponse(
            content: "```tool\n".json_encode(['name' => 'run_shell', 'input' => ['command' => 'touch '.$proof]])."\n```",
            tokensIn: 100,
            tokensOut: 20,
            raw: [],
        ),
        new ProviderResponse(content: 'Done.', tokensIn: 50, tokensOut: 10, raw: []),
    ]);

    // No '--yes' / '--yolo' in $input — the default, non-interactive-CI path.
    $exitCode = runRun(new FakeRunCommand($provider), $this->app, ['prompt' => 'touch a proof file']);

    // A denied tool call is the last tool_call event, so handle() reports FAILURE for CI.
    expect($exitCode)->toBe(1);
    expect(file_exists($proof))->toBeFalse();

    $toolCalls = array_values(array_filter(
        (new EventLog(Database::connect()))->all(),
        fn ($event) => $event['type'] === 'tool_call'
    ));

    expect($toolCalls)->toHaveCount(1);
    expect($toolCalls[0]['payload']['tool'])->toBe('run_shell');
    expect($toolCalls[0]['payload']['ok'])->toBeFalse();
});

test('with --yes, the same run_shell call IS auto-approved and actually runs', function () {
    $proof = $this->root.'/PROOF';

    $provider = new QueuedProviderClient([
        new ProviderResponse(
            content: "```tool\n".json_encode(['name' => 'run_shell', 'input' => ['command' => 'touch '.$proof]])."\n```",
            tokensIn: 100,
            tokensOut: 20,
            raw: [],
        ),
        new ProviderResponse(content: 'Done.', tokensIn: 50, tokensOut: 10, raw: []),
    ]);

    $exitCode = runRun(new FakeRunCommand($provider), $this->app, [
        'prompt' => 'touch a proof file',
        '--yes' => true,
    ]);

    expect($exitCode)->toBe(0);
    expect(file_exists($proof))->toBeTrue();
});

test('--require-edit: a turn that only runs shell and replies with prose, no edit tool call, exits FAILURE', function () {
    $provider = new QueuedProviderClient([
        new ProviderResponse(
            content: "```tool\n".json_encode(['name' => 'run_shell', 'input' => ['command' => 'echo scoping']])."\n```",
            tokensIn: 100,
            tokensOut: 20,
            raw: [],
        ),
        new ProviderResponse(content: 'Looked around, nothing to change here.', tokensIn: 50, tokensOut: 10, raw: []),
    ]);

    $exitCode = runRun(new FakeRunCommand($provider), $this->app, [
        'prompt' => 'investigate the repo',
        '--yes' => true,
        '--require-edit' => true,
    ]);

    expect($exitCode)->toBe(1);
});

test('--require-edit: a turn whose write_file call succeeds exits SUCCESS', function () {
    $provider = new QueuedProviderClient([
        new ProviderResponse(
            content: "```tool\n".json_encode(['name' => 'write_file', 'input' => ['path' => 'notes.txt', 'content' => 'edited']])."\n```",
            tokensIn: 100,
            tokensOut: 20,
            raw: [],
        ),
        new ProviderResponse(content: 'Done — wrote notes.txt.', tokensIn: 50, tokensOut: 10, raw: []),
    ]);

    $exitCode = runRun(new FakeRunCommand($provider), $this->app, [
        'prompt' => 'write a note',
        '--yes' => true,
        '--require-edit' => true,
    ]);

    expect($exitCode)->toBe(0);
    expect(file_get_contents($this->root.'/notes.txt'))->toBe('edited');
});

test('without --require-edit, a no-edit turn still exits SUCCESS (default behavior unchanged)', function () {
    $provider = new QueuedProviderClient([
        new ProviderResponse(
            content: "```tool\n".json_encode(['name' => 'run_shell', 'input' => ['command' => 'echo scoping']])."\n```",
            tokensIn: 100,
            tokensOut: 20,
            raw: [],
        ),
        new ProviderResponse(content: 'Summarized — nothing to change.', tokensIn: 50, tokensOut: 10, raw: []),
    ]);

    $exitCode = runRun(new FakeRunCommand($provider), $this->app, [
        'prompt' => 'summarize the repo',
        '--yes' => true,
    ]);

    expect($exitCode)->toBe(0);
});

test('--require-edit: a successful write_file event from a PREVIOUS session does not satisfy this run', function () {
    // Simulate a prior `paider run` that landed an edit and exited clean, in the same
    // project's .paider/paider.db -- EventLog persists across sessions, so this event
    // is sitting in the log before the run under test ever starts.
    $priorLog = new EventLog(Database::connect());
    $priorLog->append('tool_call', [
        'tool' => 'write_file',
        'input' => ['path' => 'notes.txt', 'content' => 'from an earlier session'],
        'ok' => true,
    ]);

    $provider = new QueuedProviderClient([
        new ProviderResponse(
            content: "```tool\n".json_encode(['name' => 'run_shell', 'input' => ['command' => 'echo scoping']])."\n```",
            tokensIn: 100,
            tokensOut: 20,
            raw: [],
        ),
        new ProviderResponse(content: 'Looked around, nothing to change here.', tokensIn: 50, tokensOut: 10, raw: []),
    ]);

    $exitCode = runRun(new FakeRunCommand($provider), $this->app, [
        'prompt' => 'investigate the repo',
        '--yes' => true,
        '--require-edit' => true,
    ]);

    // Must still FAIL: this run's own events have no successful edit tool call, and the
    // earlier session's write_file must not leak across the session_id boundary.
    expect($exitCode)->toBe(1);
});
