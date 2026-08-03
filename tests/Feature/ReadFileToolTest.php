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

test('reads sensitive path when approved', function () {
    $tool = new ReadFileTool(readFileToolRoot());

    $result = $tool->execute(['path' => '.env', 'approved' => true]);

    expect($result->ok)->toBeTrue();
    expect($result->output)->toBe('SECRET=1');
});
