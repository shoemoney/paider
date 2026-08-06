<?php

use App\Approval\Gate;

test('allow-once re-prompts every call for the same action', function () {
    $gate = new Gate;
    $calls = 0;
    $prompt = function () use (&$calls) {
        $calls++;

        return 'allow-once';
    };

    expect($gate->decide('run:npm test', $prompt))->toBeTrue();
    expect($gate->decide('run:npm test', $prompt))->toBeTrue();
    expect($calls)->toBe(2);
});

test('allow-session prompts once then reuses the cached decision', function () {
    $gate = new Gate;
    $calls = 0;
    $prompt = function () use (&$calls) {
        $calls++;

        return 'allow-session';
    };

    expect($gate->decide('write:src/app.php', $prompt))->toBeTrue();
    expect($gate->decide('write:src/app.php', $prompt))->toBeTrue();
    expect($calls)->toBe(1);
});

test('deny returns false and is never cached', function () {
    $gate = new Gate;
    $calls = 0;
    $prompt = function () use (&$calls) {
        $calls++;

        return 'deny';
    };

    expect($gate->decide('rm:etc', $prompt))->toBeFalse();
    expect($gate->decide('rm:etc', $prompt))->toBeFalse();
    expect($calls)->toBe(2);
});

test('two distinct action strings are tracked independently', function () {
    $gate = new Gate;

    expect($gate->decide('action:a', fn () => 'allow-session'))->toBeTrue();

    $bCalls = 0;
    $bPrompt = function () use (&$bCalls) {
        $bCalls++;

        return 'allow-session';
    };

    expect($gate->decide('action:b', $bPrompt))->toBeTrue();
    expect($bCalls)->toBe(1);

    // 'a' is still cached and does not affect 'b', and vice versa.
    expect($gate->decide('action:a', fn () => throw new Exception('should not prompt')))->toBeTrue();
});

test('yolo approves without ever calling the prompt', function () {
    $gate = new Gate(autoApprove: true);

    expect($gate->decide('rm -rf /tmp/x', fn () => throw new RuntimeException('prompted anyway')))->toBeTrue();
    expect($gate->autoApproves())->toBeTrue();
});

test('a gate with no yolo still prompts, so the default is unchanged', function () {
    $prompted = 0;
    $gate = new Gate;

    $gate->decide('ls', function () use (&$prompted) {
        $prompted++;

        return 'allow-once';
    });

    expect($prompted)->toBe(1);
    expect($gate->autoApproves())->toBeFalse();
});

test('the CLI flag turns yolo on even when the environment says nothing', function () {
    putenv(Gate::ENV_VAR);

    expect(Gate::forSession(true)->autoApproves())->toBeTrue();
    expect(Gate::forSession(false)->autoApproves())->toBeFalse();
});

test('the environment variable turns yolo on without the flag', function (string $value, bool $expected) {
    putenv(Gate::ENV_VAR.'='.$value);

    try {
        expect(Gate::forSession(false)->autoApproves())->toBe($expected);
    } finally {
        putenv(Gate::ENV_VAR);
    }
})->with([
    '1' => ['1', true],
    'true' => ['true', true],
    'yes' => ['yes', true],
    'on' => ['on', true],
    'TRUE uppercase' => ['TRUE', true],
    '0' => ['0', false],
    'false' => ['false', false],
    'empty' => ['', false],
    // Anything unrecognised must read as off: a typo'd value has to fail closed, not open.
    'garbage' => ['banana', false],
]);

test('an unset environment variable leaves yolo off', function () {
    putenv(Gate::ENV_VAR);

    expect(Gate::enabledInEnvironment())->toBeFalse();
});
