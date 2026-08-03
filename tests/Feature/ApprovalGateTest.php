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
