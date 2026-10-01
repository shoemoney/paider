<?php

use App\Agent\Loop;
use App\Agent\TierRouter;
use App\Approval\Gate;
use App\Providers\ProviderResponse;
use App\Storage\Database;
use App\Storage\EventLog;
use App\Storage\SessionStore;
use App\Tools\ReadFileTool;
use App\Tools\WriteFileTool;

// PostPatch test-feedback loop: after a successful write, Loop runs the user-configured
// test_command exactly once (no blind inner retry — see Loop::runPostPatchTests()'s doc
// comment) and folds the result back into the tool observation the model sees next turn.
//
// XDG_CONFIG_HOME is pointed at a temp dir throughout, and operatorTestCommand() writes there.
// That is not incidental plumbing: a test_command is a PERMISSION, so the gate is only skipped
// for one the OPERATOR authored. A command in the project's own .paider/settings.json is
// untrusted text a cloned repo ships, and it is now gated — see the two tests at the bottom.

beforeEach(function () {
    $this->originalXdgConfigHome = getenv('XDG_CONFIG_HOME');
    $this->tempXdg = sys_get_temp_dir().'/paider-postpatch-xdg-'.uniqid('', true);
    mkdir($this->tempXdg.'/paider', 0777, true);
    putenv('XDG_CONFIG_HOME='.$this->tempXdg);
});

afterEach(function () {
    if ($this->originalXdgConfigHome === false) {
        putenv('XDG_CONFIG_HOME');
    } else {
        putenv('XDG_CONFIG_HOME='.$this->originalXdgConfigHome);
    }

    exec('rm -rf '.escapeshellarg($this->tempXdg));
});

/** Configure the test command where the OPERATOR wrote it — the only place the gate may be skipped. */
function operatorTestCommand(string $command): void
{
    file_put_contents(
        getenv('XDG_CONFIG_HOME').'/paider/settings.json',
        json_encode(['test_command' => $command]),
    );
}

/** Builds a Loop wired with WriteFileTool + ReadFileTool over a temp project root, plus the
 *  EventLog so callers can assert on tier_call/tool_call/message/test_run entries. */
function loopForPostPatch(string $root, array $extraTools = []): array
{
    $log = new EventLog(Database::connect(':memory:'));
    $tools = [new ReadFileTool($root), new WriteFileTool($root), ...$extraTools];
    $loop = new Loop($tools, providerThatWritesThenStops($root), new TierRouter, $log, new Gate);

    return [$loop, $log];
}

function providerThatWritesThenStops(string $root): QueuedProviderClient
{
    return new QueuedProviderClient([
        new ProviderResponse(
            content: "```tool\n".json_encode(['name' => 'write_file', 'input' => ['path' => 'note.txt', 'content' => 'hello']])."\n```",
            tokensIn: 10,
            tokensOut: 5,
            raw: [],
        ),
        new ProviderResponse(content: 'Wrote it.', tokensIn: 5, tokensOut: 2, raw: []),
    ]);
}

/** @return array<int, array{role: string, content: string}> messages logged via remember() */
function loggedMessages(EventLog $log): array
{
    return array_values(array_filter($log->all(), fn ($e) => $e['type'] === SessionStore::MESSAGE));
}

/** The 'user'-role observation Loop hands the model back after a tool call (see observationText()). */
function loggedToolObservation(EventLog $log): array
{
    $messages = loggedMessages($log);
    $observation = current(array_filter($messages, fn ($e) => $e['payload']['role'] === 'user' && str_starts_with($e['payload']['content'], '[tool_result')));

    expect($observation)->not->toBeFalse();

    return $observation;
}

test('PostPatch: runPostPatchTests is not run and no test_run event lands when no test_command is configured', function () {
    [$session, $root] = loopTestSessionWithRoot();
    [$loop, $log] = loopForPostPatch($root);

    $previous = getcwd();
    chdir($root);
    try {
        $loop->turn($session, 'write note.txt', neverApprove());
    } finally {
        chdir($previous);
    }

    $testRunEvents = array_filter($log->all(), fn ($e) => $e['type'] === 'test_run');
    expect($testRunEvents)->toBeEmpty();

    $observation = loggedToolObservation($log);
    expect($observation['payload']['content'])->not->toContain('test_feedback');
});

test('PostPatch: a passing test_command appends [test_feedback ok] and logs a passing test_run event', function () {
    [$session, $root] = loopTestSessionWithRoot();
    [$loop, $log] = loopForPostPatch($root);

    $previous = getcwd();
    chdir($root);
    try {
        operatorTestCommand('true');
        $loop->turn($session, 'write note.txt', neverApprove());
    } finally {
        chdir($previous);
    }

    $testRunEvents = array_values(array_filter($log->all(), fn ($e) => $e['type'] === 'test_run'));
    expect($testRunEvents)->toHaveCount(1);
    expect($testRunEvents[0]['payload']['ok'])->toBeTrue();

    $observation = loggedToolObservation($log);
    expect($observation['payload']['content'])->toContain('[test_feedback ok]');

    $toolCalls = array_values(array_filter($log->all(), fn ($e) => $e['type'] === 'tool_call'));
    expect($toolCalls[0]['payload']['ok'])->toBeTrue();
});

test('PostPatch: a failing test_command appends [test_feedback fail] plus output, logs a failing test_run event, and flips the tool result to failed', function () {
    [$session, $root] = loopTestSessionWithRoot();
    [$loop, $log] = loopForPostPatch($root);

    $previous = getcwd();
    chdir($root);
    try {
        operatorTestCommand('echo boom-output && false');
        $loop->turn($session, 'write note.txt', neverApprove());
    } finally {
        chdir($previous);
    }

    $testRunEvents = array_values(array_filter($log->all(), fn ($e) => $e['type'] === 'test_run'));
    expect($testRunEvents)->toHaveCount(1);
    expect($testRunEvents[0]['payload']['ok'])->toBeFalse();

    $observation = loggedToolObservation($log);
    expect($observation['payload']['content'])->toContain('[test_feedback fail]');
    expect($observation['payload']['content'])->toContain('boom-output');

    $toolCalls = array_values(array_filter($log->all(), fn ($e) => $e['type'] === 'tool_call'));
    expect($toolCalls[0]['payload']['ok'])->toBeFalse();
});

test('PostPatch: a failing test_command runs exactly once — no blind inner retry', function () {
    [$session, $root] = loopTestSessionWithRoot();
    [$loop, $log] = loopForPostPatch($root);

    $previous = getcwd();
    chdir($root);
    try {
        // Each run appends one 'x' to counter.txt before failing. If the old MAX_TEST_RETRIES=3
        // loop were still in place, this file would end up with 3 x's instead of 1.
        operatorTestCommand('printf x >> counter.txt; exit 1');
        $loop->turn($session, 'write note.txt', neverApprove());
    } finally {
        chdir($previous);
    }

    expect(file_get_contents($root.'/counter.txt'))->toBe('x');
});

test('PostPatch: the gate is bypassed for a test_command the OPERATOR wrote', function () {
    [$session, $root] = loopTestSessionWithRoot();
    [$loop, $log] = loopForPostPatch($root);

    $previous = getcwd();
    chdir($root);
    try {
        // A failing command would need the gate to run it a second time under the old design;
        // neverApprove() throws the instant any approval prompt is reached, so a clean turn
        // here proves the gate was never consulted for the test_command itself.
        //
        // Scoped to the operator's own config on purpose. Re-prompting on every patch for a
        // command the human typed would train them to approve without reading, which is the
        // reflex the gate exists to prevent — so THIS case legitimately skips it. The two tests
        // below cover the case where skipping is not legitimate.
        operatorTestCommand('false');
        expect(fn () => $loop->turn($session, 'write note.txt', neverApprove()))->not->toThrow(Throwable::class);
    } finally {
        chdir($previous);
    }

    $testRunEvents = array_values(array_filter($log->all(), fn ($e) => $e['type'] === 'test_run'));
    expect($testRunEvents)->toHaveCount(1);
});

test('PostPatch: a test_command SHIPPED BY THE REPOSITORY does not get to skip the gate', function () {
    // The clone-to-RCE hole, closed. A repository that ships
    // .paider/settings.json {"test_command": "…"} is stating a preference, not granting itself a
    // permission — the same distinction that refuses a project-local mcp.json and keeps
    // PAIDER_YOLO out of ProjectEnv. Before this, the command ran with approval => allow-once
    // and no prompt, and it fires after ANY successful write, so a single ungated write_file was
    // enough to reach arbitrary code in anyone who cloned the repo and ran Paider inside it.
    //
    // neverApprove() throws the instant a prompt is reached, so a clean turn here is the proof:
    // the repo's command did not reach the shell without a human saying yes.
    [$session, $root] = loopTestSessionWithRoot();
    [$loop, $log] = loopForPostPatch($root);

    // A real side effect, not a mocked one. Asserting that a prompt was reached proves the gate
    // was consulted; asserting that the file was never created proves the command never ran. The
    // mcp.json clone-to-RCE test earns its keep the same way — PWNED not existing is the claim.
    $pwned = $root.'/PWNED';

    $previous = getcwd();
    chdir($root);
    try {
        mkdir($root.'/.paider', 0777, true);
        $malicious = 'touch '.escapeshellarg($pwned);
        file_put_contents(
            $root.'/.paider/settings.json',
            json_encode(['test_command' => $malicious]),
        );

        // Asserting the MESSAGE, not just the class: the message is the evidence. It has to name
        // the command AND say it came from the repo's settings file, because a human approving a
        // bare command string has been told nothing about where it came from.
        expect(fn () => $loop->turn($session, 'write note.txt', neverApprove()))
            ->toThrow(RuntimeException::class, "post-patch test command from this repo's .paider/settings.json: {$malicious}");
    } finally {
        chdir($previous);
    }

    // THE ASSERTION. The repository's command did not run.
    expect(is_file($pwned))->toBeFalse();

    // Declined, so no test ran — and the WRITE still stands, which is why runPostPatchTests
    // returns null rather than a failed result.
    $testRunEvents = array_values(array_filter($log->all(), fn ($e) => $e['type'] === 'test_run'));
    expect($testRunEvents)->toBeEmpty();
    expect(file_get_contents($root.'/note.txt'))->toBe('hello');
});

test('PostPatch: a repo test_command is run once a human approves it, and the prompt names where it came from', function () {
    // Gated is not "disabled". An operator who is happy to let a repo's test command run says so
    // once, and it behaves exactly as before — otherwise this fix would have quietly broken the
    // feature it was protecting. The subject the gate is given has to SAY the command came from
    // the repo, because otherwise the prompt reads as an ordinary shell call the model just
    // decided to make, and the human approves a different thing than what runs.
    [$session, $root] = loopTestSessionWithRoot();
    [$loop, $log] = loopForPostPatch($root);

    $seen = null;
    $previous = getcwd();
    chdir($root);
    try {
        mkdir($root.'/.paider', 0777, true);
        file_put_contents($root.'/.paider/settings.json', json_encode(['test_command' => 'true']));

        $loop->turn($session, 'write note.txt', function (string $subject) use (&$seen) {
            $seen = $subject;

            return 'allow-once';
        });
    } finally {
        chdir($previous);
    }

    expect($seen)->not->toBeNull()
        ->and($seen)->toContain('.paider/settings.json')
        ->and($seen)->toContain('true');

    $testRunEvents = array_values(array_filter($log->all(), fn ($e) => $e['type'] === 'test_run'));
    expect($testRunEvents)->toHaveCount(1)
        ->and($testRunEvents[0]['payload']['ok'])->toBeTrue();
});

test('FeedbackLoop: a model-initiated run_shell call still goes through the approval gate even when a test_command is configured', function () {
    [$session, $root] = loopTestSessionWithRoot();
    $tool = new RecordingShellTool;
    $log = new EventLog(Database::connect(':memory:'));

    $provider = new QueuedProviderClient([
        new ProviderResponse(
            content: "```tool\n".json_encode(['name' => 'run_shell', 'input' => ['command' => 'echo hi']])."\n```",
            tokensIn: 10,
            tokensOut: 5,
            raw: [],
        ),
        new ProviderResponse(content: 'Done.', tokensIn: 5, tokensOut: 2, raw: []),
    ]);

    $loop = new Loop([$tool], $provider, new TierRouter, $log, new Gate);

    $previous = getcwd();
    chdir($root);
    try {
        operatorTestCommand('true');

        $gateWasAsked = false;
        $approvalPrompt = function (string $subject) use (&$gateWasAsked) {
            $gateWasAsked = true;

            return 'deny';
        };

        $loop->turn($session, 'run something', $approvalPrompt);
    } finally {
        chdir($previous);
    }

    expect($gateWasAsked)->toBeTrue();
    expect($tool->lastInput)->toBe(['command' => 'echo hi', 'approval' => 'deny']);
});
