<?php

use App\Storage\Database;
use App\Storage\EventLog;
use App\Storage\ProjectEnv;
use App\Storage\SessionStore;

function sessionLog(): EventLog
{
    return new EventLog(Database::connect(':memory:'));
}

test('messages project back in the order they were appended', function () {
    $log = sessionLog();
    $log->append(SessionStore::MESSAGE, ['role' => 'user', 'content' => 'first']);
    $log->append(SessionStore::MESSAGE, ['role' => 'assistant', 'content' => 'second']);
    $log->append(SessionStore::MESSAGE, ['role' => 'user', 'content' => 'third']);

    expect(array_column((new SessionStore($log))->messages(), 'content'))
        ->toBe(['first', 'second', 'third']);
});

test('unrelated event types are ignored, so the cost log does not become conversation', function () {
    $log = sessionLog();
    $log->append('tier_call', ['tier' => 'orchestrator', 'tokens_in' => 10]);
    $log->append(SessionStore::MESSAGE, ['role' => 'user', 'content' => 'only me']);
    $log->append('tool_call', ['tool' => 'run_shell', 'ok' => true]);

    expect((new SessionStore($log))->messages())->toBe([['role' => 'user', 'content' => 'only me']]);
});

test('a stored system message is never replayed, so the context file cannot double-post', function () {
    // Session's constructor re-derives the system message from PAIDER.md/CLAUDE.md/AGENTS.md on
    // every run. PLAN.md names this as the resume gotcha: replaying a stored copy on top of that
    // posts it twice, and pins a stale copy of a file that may have been edited since.
    $log = sessionLog();
    $log->append(SessionStore::MESSAGE, ['role' => 'system', 'content' => 'stale project brief']);
    $log->append(SessionStore::MESSAGE, ['role' => 'user', 'content' => 'hello']);

    $messages = (new SessionStore($log))->messages();

    expect($messages)->toBe([['role' => 'user', 'content' => 'hello']]);
    expect(array_column($messages, 'role'))->not->toContain('system');
});

test('a malformed row is skipped rather than making the whole conversation unresumable', function () {
    $log = sessionLog();
    $log->append(SessionStore::MESSAGE, ['role' => 'user', 'content' => 'good']);
    $log->append(SessionStore::MESSAGE, ['role' => 'user']);                    // no content
    $log->append(SessionStore::MESSAGE, ['content' => 'no role']);              // no role
    $log->append(SessionStore::MESSAGE, ['role' => 'user', 'content' => ['a']]); // wrong type
    $log->append(SessionStore::MESSAGE, ['role' => 'assistant', 'content' => 'also good']);

    expect(array_column((new SessionStore($log))->messages(), 'content'))->toBe(['good', 'also good']);
});

test('the replay window keeps the most recent messages, not the oldest', function () {
    $log = sessionLog();

    foreach (range(1, 10) as $n) {
        $log->append(SessionStore::MESSAGE, ['role' => 'user', 'content' => "m{$n}"]);
    }

    expect(array_column((new SessionStore($log))->messages(3), 'content'))->toBe(['m8', 'm9', 'm10']);
});

test('a window of zero replays nothing, which is how resume is switched off', function () {
    $log = sessionLog();
    $log->append(SessionStore::MESSAGE, ['role' => 'user', 'content' => 'hi']);

    expect((new SessionStore($log))->messages(0))->toBe([]);
});

test('context files project to what is still added after drops', function () {
    $log = sessionLog();
    $log->append(SessionStore::FILE_ADDED, ['path' => 'a.php']);
    $log->append(SessionStore::FILE_ADDED, ['path' => 'b.php']);
    $log->append(SessionStore::FILE_DROPPED, ['path' => 'a.php']);
    $log->append(SessionStore::FILE_ADDED, ['path' => 'c.php']);

    expect((new SessionStore($log))->contextFiles())->toBe(['b.php', 'c.php']);
});

test('re-adding a dropped file brings it back — forgetting is an event, not a delete', function () {
    $log = sessionLog();
    $log->append(SessionStore::FILE_ADDED, ['path' => 'a.php']);
    $log->append(SessionStore::FILE_DROPPED, ['path' => 'a.php']);
    $log->append(SessionStore::FILE_ADDED, ['path' => 'a.php']);

    expect((new SessionStore($log))->contextFiles())->toBe(['a.php']);
});

test('an empty log reports itself empty so a fresh project shows no resume banner', function () {
    $log = sessionLog();
    $log->append('tier_call', ['tier' => 'orchestrator']);

    expect((new SessionStore($log))->isEmpty())->toBeTrue();

    $log->append(SessionStore::MESSAGE, ['role' => 'user', 'content' => 'hi']);

    expect((new SessionStore($log))->isEmpty())->toBeFalse();
});

test('the resume window is configurable and falls back to the default on nonsense', function (?string $value, int $expected) {
    ProjectEnv::forget();
    $value === null ? putenv('PAIDER_RESUME_MESSAGES') : putenv("PAIDER_RESUME_MESSAGES={$value}");

    try {
        expect(SessionStore::resumeWindow())->toBe($expected);
    } finally {
        putenv('PAIDER_RESUME_MESSAGES');
        ProjectEnv::forget();
    }
})->with([
    'unset' => [null, SessionStore::DEFAULT_WINDOW],
    'explicit' => ['10', 10],
    'zero disables' => ['0', 0],
    // Negative and non-numeric must not produce a negative slice, which would silently
    // invert the window and replay the OLDEST messages instead of the newest.
    'negative clamps' => ['-5', 0],
    'garbage falls back' => ['banana', SessionStore::DEFAULT_WINDOW],
]);
