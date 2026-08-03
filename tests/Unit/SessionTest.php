<?php

use App\Agent\Session;
use App\Agent\TierRouter;
use App\Tools\ReadFileTool;

function sessionRoot(): string
{
    $root = sys_get_temp_dir().'/paider-session-'.uniqid();
    mkdir($root, recursive: true);

    return realpath($root);
}

function makeSession(string $root): Session
{
    return new Session(new ReadFileTool($root), $root);
}

test('addFile stores a stamp that a subsequent on-disk mutation invalidates', function () {
    $root = sessionRoot();
    file_put_contents($root.'/notes.txt', 'original content');

    $session = makeSession($root);
    $result = $session->addFile('notes.txt');

    expect($result->ok)->toBeTrue();

    $stampAtAddTime = $session->contextFiles()['notes.txt']['stamp'];

    file_put_contents($root.'/notes.txt', 'mutated content');
    $freshHash = hash('sha256', file_get_contents($root.'/notes.txt'));

    expect($stampAtAddTime)->not->toBe($freshHash);
});

test('dropFile removes a file from context', function () {
    $root = sessionRoot();
    file_put_contents($root.'/notes.txt', 'hello');

    $session = makeSession($root);
    $session->addFile('notes.txt');
    expect($session->contextFiles())->toHaveKey('notes.txt');

    $session->dropFile('notes.txt');
    expect($session->contextFiles())->not->toHaveKey('notes.txt');
});

test('undo reports empty on an empty stack', function () {
    $session = makeSession(sessionRoot());

    expect($session->undo())->toBe(['status' => 'empty']);
});

test('recordApply and undo round-trip a write', function () {
    $root = sessionRoot();
    $path = $root.'/file.txt';
    file_put_contents($path, 'before');

    $session = makeSession($root);

    $session->recordApply($path, 'before');
    file_put_contents($path, 'after'); // simulates the write Loop performs after recordApply
    $session->pushHistory('assistant', 'applied a write'); // seals the entry's post-apply hash

    $result = $session->undo();

    expect($result)->toBe(['status' => 'ok', 'path' => $path]);
    expect(file_get_contents($path))->toBe('before');
});

test('recordApply and undo round-trip a new file by deleting it', function () {
    $root = sessionRoot();
    $path = $root.'/new.txt';

    $session = makeSession($root);

    $session->recordApply($path, null);
    file_put_contents($path, 'brand new content');
    $session->pushHistory('assistant', 'created a file');

    $result = $session->undo();

    expect($result)->toBe(['status' => 'ok', 'path' => $path]);
    expect(is_file($path))->toBeFalse();
});

test('undo reports conflict and restores nothing when the file was hand-edited after the apply', function () {
    $root = sessionRoot();
    $path = $root.'/file.txt';
    file_put_contents($path, 'before');

    $session = makeSession($root);

    $session->recordApply($path, 'before');
    file_put_contents($path, 'after'); // the apply
    $session->pushHistory('assistant', 'applied a write'); // seals postApplyHash = hash('after')

    file_put_contents($path, 'hand-edited'); // user edits the file before calling /undo

    $result = $session->undo();

    expect($result['status'])->toBe('conflict');
    expect($result['path'])->toBe($path);
    expect(file_get_contents($path))->toBe('hand-edited');
});

test('setTierOverride changes what TierRouter::resolve later returns', function () {
    $session = makeSession(sessionRoot());

    $session->setTierOverride('coder', 'override/model-x');

    $resolved = (new TierRouter)->resolve('edit', $session->tierOverrides());

    expect($resolved['model'])->toBe('override/model-x');
});

test('setTierOverride throws on an invalid tier name', function () {
    $session = makeSession(sessionRoot());

    $session->setTierOverride('not-a-real-tier', 'some/model');
})->throws(InvalidArgumentException::class);

test('loads PAIDER.md over CLAUDE.md and AGENTS.md as the initial system history entry', function () {
    $root = sessionRoot();
    file_put_contents($root.'/PAIDER.md', 'paider instructions');
    file_put_contents($root.'/CLAUDE.md', 'claude instructions');

    $session = makeSession($root);

    expect($session->history())->toBe([
        ['role' => 'system', 'content' => 'paider instructions'],
    ]);
});

test('constructing with no context file present pushes no history', function () {
    $session = makeSession(sessionRoot());

    expect($session->history())->toBe([]);
});
