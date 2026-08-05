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
    $pidFile = $root.'/child.pid';
    $tool = new ShellTool($root, timeoutSeconds: 1);
    $childPid = null;

    try {
        // The trap makes the ignored-TERM disposition inherit into the backgrounded `sleep`,
        // so it survives ShellTool's SIGTERM pass and must be reached by SIGKILL. `wait` keeps
        // the parent shell blocked so the tree is intact when the deadline fires.
        $start = microtime(true);
        $result = $tool->execute([
            'command' => "trap '' TERM; sleep 20 & echo \$! > ".escapeshellarg($pidFile).'; wait',
            'approval' => 'allow-once',
        ]);
        $elapsed = microtime(true) - $start;

        expect($result->ok)->toBeFalse();
        expect($result->meta['timed_out'])->toBeTrue();
        expect($elapsed)->toBeLessThan(5.0);

        $childPid = (int) trim((string) file_get_contents($pidFile));
        expect($childPid)->toBeGreaterThan(0);

        // Generous settle margin on top of ShellTool's own internal kill/reap waits, so this
        // doesn't flake on a loaded box.
        usleep(750_000);

        $alive = trim((string) shell_exec('ps -p '.escapeshellarg((string) $childPid).' -o pid= 2>/dev/null'));
        expect($alive)->toBe('');
    } finally {
        // Best-effort: never leak a live `sleep` if an assertion above threw first.
        if ($childPid) {
            @exec('kill -9 '.escapeshellarg((string) $childPid).' 2>/dev/null');
        }
    }
});

test('a backgrounded descendant still in the process tree at the deadline is dead after timeout', function () {
    $root = shellToolRoot();
    $pidFile = $root.'/child.pid';
    $tool = new ShellTool($root, timeoutSeconds: 1);
    $childPid = null;

    try {
        // The background `sleep 20 &` is still a live descendant of the tracked `sh -c` pid at
        // the 1s deadline (it hasn't detached via `( ... & )`), so ShellTool's descendant sweep
        // must reach it. `wait` keeps the parent shell blocked so the tree is intact when the
        // deadline fires.
        $result = $tool->execute([
            'command' => 'sleep 20 & echo $! > '.escapeshellarg($pidFile).'; wait',
            'approval' => 'allow-once',
        ]);

        expect($result->ok)->toBeFalse();
        expect($result->meta['timed_out'])->toBeTrue();
        expect(file_exists($pidFile))->toBeTrue();

        $childPid = (int) trim((string) file_get_contents($pidFile));
        expect($childPid)->toBeGreaterThan(0);

        // Generous settle margin on top of ShellTool's own internal kill/reap waits, so this
        // doesn't flake on a loaded box.
        usleep(750_000);

        $alive = trim((string) shell_exec('ps -p '.escapeshellarg((string) $childPid).' -o pid= 2>/dev/null'));
        expect($alive)->toBe('');
    } finally {
        // Best-effort: never leak a live `sleep` if an assertion above threw first.
        if ($childPid) {
            @exec('kill -9 '.escapeshellarg((string) $childPid).' 2>/dev/null');
        }
    }
});

test('a shell command cannot read an unlisted parent environment variable', function () {
    $root = shellToolRoot();
    $sentinelName = 'PAIDER_TEST_SECRET_'.str_replace('.', '', uniqid('', true));
    putenv($sentinelName.'=totally-secret-value');

    try {
        $tool = new ShellTool($root);
        $result = $tool->execute(['command' => 'echo $'.$sentinelName, 'approval' => 'allow-once']);
    } finally {
        putenv($sentinelName);
    }

    expect($result->ok)->toBeTrue();
    expect($result->output)->not->toContain('totally-secret-value');
});
