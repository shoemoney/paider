<?php

use App\Support\Banner;

// The suite runs with stdout redirected, so render() takes its undecorated branch here and
// these assertions see the plain art — which is the point: the banner must not leak escape
// bytes into a piped or logged run.
test('the banner emits no escape sequences when stdout is not a terminal', function () {
    expect(Banner::render())->not->toContain("\e");
});

test('the subtitle is set into the wordmark at the column where the A stands', function () {
    $lines = explode(PHP_EOL, trim(Banner::render(), PHP_EOL));
    $subtitleLine = array_values(array_filter($lines, fn ($l) => str_contains($l, 'The PHP Aider')))[0];

    // Column 8 is the A's left stroke on the widest row — if the art or the offset drifts,
    // the subtitle stops lining up under it and this catches it.
    expect(strpos($subtitleLine, 'The PHP Aider'))->toBe(8);
    expect($lines[4][8])->toBe('#');
});

test('every art row survives the gradient walk intact', function () {
    $lines = explode(PHP_EOL, trim(Banner::render(), PHP_EOL));

    expect($lines)->toHaveCount(7);
    expect($lines[1])->toBe(' mmmm     ##   mmm     mmm#   mmm    m mm');
});
