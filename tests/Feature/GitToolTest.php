<?php

use App\Tools\GitTool;

beforeEach(function () {
    $this->root = sys_get_temp_dir().DIRECTORY_SEPARATOR.'paider-gittool-'.uniqid();
    mkdir($this->root, recursive: true);

    exec('git init '.escapeshellarg($this->root).' 2>&1');
    exec('git -C '.escapeshellarg($this->root).' config user.email test@example.com 2>&1');
    exec('git -C '.escapeshellarg($this->root).' config user.name Test 2>&1');

    $this->root = realpath($this->root);
    $this->tool = new GitTool($this->root);
});

test('add stages a new file', function () {
    file_put_contents($this->root.'/new-file.txt', 'content');

    $result = $this->tool->execute(['op' => 'add', 'path' => 'new-file.txt']);

    expect($result->ok)->toBeTrue();

    exec('git -C '.escapeshellarg($this->root).' status --porcelain', $output);
    expect($output[0] ?? '')->toBe('A  new-file.txt');
});

test('commit with an empty message fails without creating a commit', function () {
    file_put_contents($this->root.'/staged.txt', 'content');
    $this->tool->execute(['op' => 'add', 'path' => 'staged.txt']);

    $result = $this->tool->execute(['op' => 'commit', 'message' => '']);

    expect($result->ok)->toBeFalse();
    expect($result->output)->toBe('empty commit message');

    exec('git -C '.escapeshellarg($this->root).' rev-list --all --count', $output);
    expect(trim($output[0] ?? ''))->toBe('0');
});

test('commit with a message creates a real commit', function () {
    file_put_contents($this->root.'/staged.txt', 'content');
    $this->tool->execute(['op' => 'add', 'path' => 'staged.txt']);

    $result = $this->tool->execute(['op' => 'commit', 'message' => 'add staged file']);

    expect($result->ok)->toBeTrue();

    exec('git -C '.escapeshellarg($this->root).' log -1 --format=%s', $output);
    expect($output[0] ?? '')->toBe('add staged file');
});

test('diff returns non-empty output for an unstaged change', function () {
    file_put_contents($this->root.'/tracked.txt', 'original');
    $this->tool->execute(['op' => 'add', 'path' => 'tracked.txt']);
    $this->tool->execute(['op' => 'commit', 'message' => 'seed file']);

    file_put_contents($this->root.'/tracked.txt', 'changed');

    $result = $this->tool->execute(['op' => 'diff']);

    expect($result->ok)->toBeTrue();
    expect($result->output)->toContain('changed');
});

test('diff on a sensitive path requires approval and withholds contents', function () {
    file_put_contents($this->root.'/.env', 'SECRET=original');
    $this->tool->execute(['op' => 'add', 'path' => '.env']);
    $this->tool->execute(['op' => 'commit', 'message' => 'seed env']);

    file_put_contents($this->root.'/.env', 'SECRET=leaked-value');

    $result = $this->tool->execute(['op' => 'diff']);

    expect($result->ok)->toBeFalse();
    expect($result->meta['needs_approval'])->toBeTrue();
    expect($result->output)->not->toContain('leaked-value');
});

test('diff on a sensitive path with approved returns the content', function () {
    file_put_contents($this->root.'/.env', 'SECRET=original');
    $this->tool->execute(['op' => 'add', 'path' => '.env']);
    $this->tool->execute(['op' => 'commit', 'message' => 'seed env']);

    file_put_contents($this->root.'/.env', 'SECRET=leaked-value');

    $result = $this->tool->execute(['op' => 'diff', 'approved' => true]);

    expect($result->ok)->toBeTrue();
    expect($result->output)->toContain('leaked-value');
});
