<?php

use App\Support\Banner;
use App\Support\Palette;

// Banner::render() -> Palette::gradient() -> Palette::enabled(), whose final rung is
// !stream_isatty(STDOUT) — true in CI (piped) but FALSE when this suite is run interactively
// per the house rule ("vendor/bin/pest"), which used to make these tests fail on a real
// terminal. Forcing the explicit override removes the dependency on how the suite happens to
// be invoked, matching what every other assertion in this file already assumes.
beforeEach(function () {
    putenv('PAIDER_COLOR=0');
    Palette::forget();
});

afterEach(function () {
    putenv('PAIDER_COLOR');
    Palette::forget();
});

test('the banner emits no escape sequences when colour is explicitly disabled', function () {
    // Renamed deliberately. The beforeEach above forces PAIDER_COLOR=0, so this exercises the
    // EXPLICIT-OVERRIDE rung of the ladder, not the non-tty rung its old name claimed. A test
    // whose name points at a different guard than the one it trips is how a rung silently
    // stops being covered — the real non-tty check now lives in PaletteTest as a subprocess.
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
