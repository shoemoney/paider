<?php

use App\Tools\ToolResult;

test('ok() constructs a successful result', function () {
    $result = ToolResult::ok('did the thing', ['duration' => 12]);

    expect($result->ok)->toBeTrue()
        ->and($result->output)->toBe('did the thing')
        ->and($result->meta)->toBe(['duration' => 12]);
});

test('fail() constructs a failed result', function () {
    $result = ToolResult::fail('nope', ['reason' => 'denied']);

    expect($result->ok)->toBeFalse()
        ->and($result->output)->toBe('nope')
        ->and($result->meta)->toBe(['reason' => 'denied']);
});

test('meta defaults to an empty array', function () {
    expect(ToolResult::ok('x')->meta)->toBe([])
        ->and(ToolResult::fail('x')->meta)->toBe([]);
});

test('ToolResult is immutable', function () {
    $result = ToolResult::ok('x');

    expect(fn () => $result->ok = false)->toThrow(Error::class);
});
