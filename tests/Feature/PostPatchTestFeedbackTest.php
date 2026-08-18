<?php

use App\Agent\Loop;
use App\Agent\TierRouter;
use App\Approval\Gate;
use App\Providers\ProviderResponse;
use App\Storage\Database;
use App\Storage\EventLog;
use App\Support\SettingsStore;
use App\Tools\ReadFileTool;
use App\Tools\WriteFileTool;

// PostPatch test-feedback loop: after a successful write, Loop runs the user-configured
// test_command exactly once (no blind inner retry — see Loop::runPostPatchTests()'s doc
// comment) and folds the result back into the tool observation the model sees next turn.

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
    return array_values(array_filter($log->all(), fn ($e) => $e['type'] === \App\Storage\SessionStore::MESSAGE));
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
        SettingsStore::setTestCommand('true');
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
        SettingsStore::setTestCommand('echo boom-output && false');
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
        SettingsStore::setTestCommand('printf x >> counter.txt; exit 1');
        $loop->turn($session, 'write note.txt', neverApprove());
    } finally {
        chdir($previous);
    }

    expect(file_get_contents($root.'/counter.txt'))->toBe('x');
});

test('PostPatch: the gate is bypassed for the configured test_command (allow-once, no approval prompt)', function () {
    [$session, $root] = loopTestSessionWithRoot();
    [$loop, $log] = loopForPostPatch($root);

    $previous = getcwd();
    chdir($root);
    try {
        // A failing command would need the gate to run it a second time under the old design;
        // neverApprove() throws the instant any approval prompt is reached, so a clean turn
        // here proves the gate was never consulted for the test_command itself.
        SettingsStore::setTestCommand('false');
        expect(fn () => $loop->turn($session, 'write note.txt', neverApprove()))->not->toThrow(Throwable::class);
    } finally {
        chdir($previous);
    }

    $testRunEvents = array_values(array_filter($log->all(), fn ($e) => $e['type'] === 'test_run'));
    expect($testRunEvents)->toHaveCount(1);
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
        SettingsStore::setTestCommand('true');

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
