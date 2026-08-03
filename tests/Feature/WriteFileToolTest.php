<?php

use App\Tools\WriteFileTool;

function writeFileToolRoot(): string
{
    $root = sys_get_temp_dir().DIRECTORY_SEPARATOR.'paider-writefile-'.uniqid();
    mkdir($root, recursive: true);

    return realpath($root);
}

function tmpArtifactsIn(string $dir): array
{
    return glob($dir.'/*.paider-tmp-*') ?: [];
}

test('writes then reads back a new file', function () {
    $root = writeFileToolRoot();
    $tool = new WriteFileTool($root);

    $result = $tool->execute(['path' => 'out.txt', 'content' => 'hi there', 'approved' => true]);

    expect($result->ok)->toBeTrue();
    expect($result->meta['bytes'])->toBe(8);
    expect(file_get_contents($root.'/out.txt'))->toBe('hi there');
});

test('overwrite is atomic and leaves no temp file', function () {
    $root = writeFileToolRoot();
    $tool = new WriteFileTool($root);

    $tool->execute(['path' => 'out.txt', 'content' => 'first']);
    $result = $tool->execute(['path' => 'out.txt', 'content' => 'second']);

    expect($result->ok)->toBeTrue();
    expect(file_get_contents($root.'/out.txt'))->toBe('second');
    expect(tmpArtifactsIn($root))->toBe([]);
});

test('rejects a traversal write with nothing created on disk', function () {
    $root = writeFileToolRoot();
    $tool = new WriteFileTool($root);

    $result = $tool->execute(['path' => '../../etc/passwd', 'content' => 'pwned']);

    expect($result->ok)->toBeFalse();
    expect($result->output)->toBe('path escapes project root');
    expect(file_exists(dirname($root).'/etc/passwd'))->toBeFalse();
});

test('creates missing parent directories within root', function () {
    $root = writeFileToolRoot();
    $tool = new WriteFileTool($root);

    $result = $tool->execute(['path' => 'a/b/c/deep.txt', 'content' => 'deep']);

    expect($result->ok)->toBeTrue();
    expect(file_get_contents($root.'/a/b/c/deep.txt'))->toBe('deep');
});

test('a write that would escape root creates nothing at all', function () {
    $root = writeFileToolRoot();
    $tool = new WriteFileTool($root);

    $tool->execute(['path' => '../escaped-dir/file.txt', 'content' => 'nope']);

    expect(is_dir(dirname($root).'/escaped-dir'))->toBeFalse();
    expect(scandir($root))->toBe(['.', '..']);
});

test('rejects sensitive path without approval and does not write', function () {
    $root = writeFileToolRoot();
    $tool = new WriteFileTool($root);

    $result = $tool->execute(['path' => '.env', 'content' => 'SECRET=1']);

    expect($result->ok)->toBeFalse();
    expect($result->meta['needs_approval'] ?? null)->toBeTrue();
    expect(file_exists($root.'/.env'))->toBeFalse();
});

test('writes sensitive path when approved', function () {
    $root = writeFileToolRoot();
    $tool = new WriteFileTool($root);

    $result = $tool->execute(['path' => '.env', 'content' => 'SECRET=1', 'approved' => true]);

    expect($result->ok)->toBeTrue();
    expect(file_get_contents($root.'/.env'))->toBe('SECRET=1');
});

test('rejects a write through a symlinked directory that escapes root', function () {
    $root = writeFileToolRoot();
    $outside = sys_get_temp_dir().DIRECTORY_SEPARATOR.'paider-writefile-outside-'.uniqid();
    mkdir($outside, recursive: true);

    $link = $root.'/linked-dir';
    symlink($outside, $link);

    $tool = new WriteFileTool($root);

    $result = $tool->execute(['path' => 'linked-dir/pwned.txt', 'content' => 'pwned']);

    expect($result->ok)->toBeFalse();
    expect($result->output)->toBe('path escapes project root');
    expect(file_exists($outside.'/pwned.txt'))->toBeFalse();
});
