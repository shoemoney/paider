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
    $subtitleLine = array_values(array_filter($lines, fn ($l) => str_contains($l, 'The PHP coding agent')))[0];

    // Column 8 is the A's left stroke on the widest row — if the art or the offset drifts,
    // the subtitle stops lining up under it and this catches it.
    expect(strpos($subtitleLine, 'The PHP coding agent'))->toBe(8);
    expect($lines[4][8])->toBe('#');
});

test('the banner never names a competitor', function () {
    // The subtitle was "The PHP Aider" — a pre-rename leftover. Aider is the Python agent this
    // project benchmarks against in its own README, so shipping that name in the startup banner
    // was a collision in the one piece of output every user sees before typing anything. No test
    // asserted what the subtitle SAYS, only where it sat, so the stale name was invisible to
    // the suite and only surfaced when a vision reviewer read the TUI screenshot.
    //
    // Asserting a negative, deliberately: naming the competitor in the assertion is the point.
    $rendered = Banner::render();

    expect($rendered)->not->toContain('Aider')
        ->and($rendered)->not->toContain('aider')
        ->and($rendered)->toContain('The PHP coding agent');
});

test('the banner subtitle is stable, so a rename cannot silently leave a stale one', function () {
    // Guards the specific failure: the subtitle said "The PHP Aider" (a pre-rename leftover)
    // and nothing noticed, because every banner test asserted WHERE the subtitle sat and never
    // WHAT it said. Pinning the exact string makes a rename a deliberate edit here rather than an
    // invisible one.
    //
    // The ASCII art rows cannot be asserted for the project name — they are literal # and m
    // glyphs, not letters — so the subtitle is the only human-readable text in the banner.
    expect(Banner::render())->toContain('The PHP coding agent');
});

test('every art row survives the gradient walk intact', function () {
    $lines = explode(PHP_EOL, trim(Banner::render(), PHP_EOL));

    expect($lines)->toHaveCount(7);
    expect($lines[1])->toBe(' mmmm     ##   mmm     mmm#   mmm    m mm');
});
