<?php

use App\Tools\ReadFileTool;

function readFileToolRoot(): string
{
    static $root = null;

    if ($root === null) {
        $root = sys_get_temp_dir().DIRECTORY_SEPARATOR.'paider-readfile-'.uniqid();
        mkdir($root, recursive: true);
        $root = realpath($root);
        file_put_contents($root.'/hello.txt', 'hello world');
        file_put_contents($root.'/.env', 'SECRET=1');
    }

    return $root;
}

test('reads an ordinary file', function () {
    $tool = new ReadFileTool(readFileToolRoot());

    $result = $tool->execute(['path' => 'hello.txt']);

    expect($result->ok)->toBeTrue();
    expect($result->output)->toBe('hello world');
});

test('rejects traversal outside project root', function () {
    $tool = new ReadFileTool(readFileToolRoot());

    $result = $tool->execute(['path' => '../../etc/passwd']);

    expect($result->ok)->toBeFalse();
    expect($result->output)->toBe('path escapes project root');
});

test('rejects sensitive path without approval and leaks no content', function () {
    $tool = new ReadFileTool(readFileToolRoot());

    $result = $tool->execute(['path' => '.env']);

    expect($result->ok)->toBeFalse();
    expect($result->meta['needs_approval'] ?? null)->toBeTrue();
    expect($result->meta['reason'] ?? null)->toBe('secrets');
    expect($result->output)->not->toContain('SECRET=1');
});

test('reads sensitive path when approved via the execute() parameter', function () {
    $tool = new ReadFileTool(readFileToolRoot());

    $result = $tool->execute(['path' => '.env'], true);

    expect($result->ok)->toBeTrue();
    expect($result->output)->toBe('SECRET=1');
});

test('a model-shaped approved=true inside $input cannot self-approve, called directly with no Loop involved', function () {
    $tool = new ReadFileTool(readFileToolRoot());

    $result = $tool->execute(['path' => '.env', 'approved' => true]);

    expect($result->ok)->toBeFalse();
    expect($result->meta['needs_approval'] ?? null)->toBeTrue();
    expect($result->output)->not->toContain('SECRET=1');
});

test('rejects a read through a symlinked directory that escapes root', function () {
    $root = readFileToolRoot();
    $outside = sys_get_temp_dir().DIRECTORY_SEPARATOR.'paider-readfile-outside-'.uniqid();
    mkdir($outside, recursive: true);
    file_put_contents($outside.'/secret.txt', 'top secret');

    $link = $root.'/linked-dir';
    if (! file_exists($link)) {
        symlink($outside, $link);
    }

    $tool = new ReadFileTool($root);

    $result = $tool->execute(['path' => 'linked-dir/secret.txt']);

    expect($result->ok)->toBeFalse();
    expect($result->output)->toBe('path escapes project root');
});

test('rejects a non-string path instead of throwing', function () {
    $tool = new ReadFileTool(readFileToolRoot());

    $result = $tool->execute(['path' => ['nope']]);

    expect($result->ok)->toBeFalse();
    expect($result->meta['invalid_input'] ?? null)->toBeTrue();
});

test('rejects a call missing path instead of throwing', function () {
    $tool = new ReadFileTool(readFileToolRoot());

    $result = $tool->execute([]);

    expect($result->ok)->toBeFalse();
    expect($result->meta['invalid_input'] ?? null)->toBeTrue();
});
