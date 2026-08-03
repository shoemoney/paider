<?php

use App\Tools\ShellTool;

function shellToolRoot(): string
{
    $root = sys_get_temp_dir().DIRECTORY_SEPARATOR.'paider-shelltool-'.uniqid();
    mkdir($root, recursive: true);

    return realpath($root);
}

test('missing approval key fails closed and runs nothing', function () {
    $root = shellToolRoot();
    $marker = $root.'/marker';
    $tool = new ShellTool($root);

    $result = $tool->execute(['command' => 'touch '.escapeshellarg($marker)]);

    expect($result->ok)->toBeFalse();
    expect($result->meta['needs_approval'])->toBeTrue();
    expect(file_exists($marker))->toBeFalse();
});

test('deny fails and runs nothing', function () {
    $root = shellToolRoot();
    $marker = $root.'/marker';
    $tool = new ShellTool($root);

    $result = $tool->execute(['command' => 'touch '.escapeshellarg($marker), 'approval' => 'deny']);

    expect($result->ok)->toBeFalse();
    expect($result->output)->toBe('denied');
    expect(file_exists($marker))->toBeFalse();
});

test('allow-once runs the command and captures stdout', function () {
    $root = shellToolRoot();
    $tool = new ShellTool($root);

    $result = $tool->execute(['command' => 'echo hello', 'approval' => 'allow-once']);

    expect($result->ok)->toBeTrue();
    expect(trim($result->output))->toBe('hello');
    expect($result->meta['exit_code'])->toBe(0);
});

test('a command exceeding the timeout is killed and reports timed_out', function () {
    $root = shellToolRoot();
    $tool = new ShellTool($root, timeoutSeconds: 1);

    $result = $tool->execute(['command' => 'sleep 5', 'approval' => 'allow-once']);

    expect($result->ok)->toBeFalse();
    expect($result->meta['timed_out'])->toBeTrue();
});

test('a command that traps SIGTERM is still killed within roughly the timeout window', function () {
    $root = shellToolRoot();
    $tool = new ShellTool($root, timeoutSeconds: 1);

    $start = microtime(true);
    $result = $tool->execute(['command' => "trap '' TERM; sleep 20", 'approval' => 'allow-once']);
    $elapsed = microtime(true) - $start;

    expect($result->ok)->toBeFalse();
    expect($result->meta['timed_out'])->toBeTrue();
    expect($elapsed)->toBeLessThan(5.0);
});
