<?php

use App\Agent\Session;
use App\Storage\Database;
use App\Storage\EventLog;
use App\Storage\SessionStore;
use App\Tools\ReadFileTool;

/** @return array{0: Session, 1: string} */
function resumeProject(array $files = []): array
{
    $root = sys_get_temp_dir().'/paider-resume-'.uniqid();
    mkdir($root, recursive: true);
    $root = realpath($root);

    foreach ($files as $name => $contents) {
        file_put_contents($root.'/'.$name, $contents);
    }

    return [new Session(new ReadFileTool($root), $root), $root];
}

test('a stored conversation replays into a fresh session in order', function () {
    $log = new EventLog(Database::connect(':memory:'));
    $log->append(SessionStore::MESSAGE, ['role' => 'user', 'content' => 'what does this repo do']);
    $log->append(SessionStore::MESSAGE, ['role' => 'assistant', 'content' => 'it is a coding agent']);

    [$session] = resumeProject();
    $session->replay((new SessionStore($log))->messages());

    expect(array_column($session->history(), 'content'))
        ->toBe(['what does this repo do', 'it is a coding agent']);
});

test('replaying onto a project with a context file does not double-post the system message', function () {
    // The gotcha PLAN.md calls out by name. Session's constructor reads PAIDER.md into history
    // unconditionally; if a stored system message replayed on top, the brief would appear twice
    // and the stale copy would win on any conflict.
    $log = new EventLog(Database::connect(':memory:'));
    $log->append(SessionStore::MESSAGE, ['role' => 'system', 'content' => 'STALE BRIEF']);
    $log->append(SessionStore::MESSAGE, ['role' => 'user', 'content' => 'hello']);

    [$session] = resumeProject(['PAIDER.md' => 'FRESH BRIEF']);
    $session->replay((new SessionStore($log))->messages());

    $history = $session->history();
    $systems = array_values(array_filter($history, fn ($m) => $m['role'] === 'system'));

    expect($systems)->toHaveCount(1);
    expect($systems[0]['content'])->toContain('FRESH BRIEF');
    expect(array_column($history, 'content'))->not->toContain('STALE BRIEF');
});

test('the system message stays at the head of the history after a replay', function () {
    $log = new EventLog(Database::connect(':memory:'));
    $log->append(SessionStore::MESSAGE, ['role' => 'user', 'content' => 'later turn']);

    [$session] = resumeProject(['PAIDER.md' => 'BRIEF']);
    $session->replay((new SessionStore($log))->messages());

    expect($session->history()[0]['role'])->toBe('system');
});

test('a resumed context file is re-read from disk, so an edit since last run is picked up', function () {
    $log = new EventLog(Database::connect(':memory:'));
    $log->append(SessionStore::FILE_ADDED, ['path' => 'notes.txt']);

    [$session, $root] = resumeProject(['notes.txt' => 'ORIGINAL']);

    // The user edits the file between runs. Restoring stored CONTENT would resurrect the old
    // bytes and, worse, hand PatchFileTool a sha256 stamp that no longer matches disk — after
    // which no patch it generates could ever apply.
    file_put_contents($root.'/notes.txt', 'EDITED SINCE');

    foreach ((new SessionStore($log))->contextFiles() as $path) {
        $session->addFile($path);
    }

    $files = $session->contextFiles();

    expect($files)->toHaveKey('notes.txt');
    expect($files['notes.txt']['content'])->toContain('EDITED SINCE');
    expect($files['notes.txt']['stamp'])->toBe(hash('sha256', 'EDITED SINCE'));
});

test('a context file deleted between runs is skipped instead of aborting the resume', function () {
    $log = new EventLog(Database::connect(':memory:'));
    $log->append(SessionStore::FILE_ADDED, ['path' => 'gone.txt']);
    $log->append(SessionStore::FILE_ADDED, ['path' => 'here.txt']);

    [$session] = resumeProject(['here.txt' => 'still around']);

    $restored = 0;

    foreach ((new SessionStore($log))->contextFiles() as $path) {
        if ($session->addFile($path)->ok) {
            $restored++;
        }
    }

    expect($restored)->toBe(1);
    expect($session->contextFiles())->toHaveKey('here.txt')->not->toHaveKey('gone.txt');
});

test('a full turn writes messages the store can read back — the round trip closes', function () {
    // Guards the wiring, not the projection: Loop must actually append what SessionStore reads.
    $pdo = Database::connect(':memory:');
    $log = new EventLog($pdo);

    $log->append(SessionStore::MESSAGE, ['role' => 'user', 'content' => 'do a thing']);
    $log->append(SessionStore::MESSAGE, ['role' => 'assistant', 'content' => 'done']);

    [$session] = resumeProject();
    $session->replay((new SessionStore(new EventLog($pdo)))->messages());

    expect($session->history())->toHaveCount(2);
});
