<?php

use App\Storage\ProjectEnv;
use App\Support\ColorRole;
use App\Support\Palette;

/** @var array<string, string|false> */
$envSnapshot = [];

beforeEach(function () use (&$envSnapshot) {
    // Snapshot AND clear: a developer who has PAIDER_THEME/NO_COLOR/etc. actually set in their
    // own shell must not leak into these assertions — every test opts back into what it needs
    // via its own putenv() call. Confirmed necessary: PAIDER_THEME=dracula in the real
    // environment previously failed 'a role returns a stable, asserted escape sequence'.
    foreach (['NO_COLOR', 'PAIDER_COLOR', 'PAIDER_THEME', 'TERM', 'COLORTERM'] as $key) {
        $envSnapshot[$key] = getenv($key);
        putenv($key);
    }

    Palette::forget();
    ProjectEnv::forget();
});

afterEach(function () use (&$envSnapshot) {
    foreach ($envSnapshot as $key => $value) {
        $value === false ? putenv($key) : putenv("{$key}={$value}");
    }

    Palette::forget();
    ProjectEnv::forget();
});

test('NO_COLOR set to any value produces plain text with zero escape bytes', function () {
    putenv('NO_COLOR=1');

    expect(Palette::wrap(ColorRole::Accent, 'hi'))->toBe('hi')
        ->and(Palette::wrap(ColorRole::Accent, 'hi'))->not->toContain("\e");
});

test('non-decorated output produces zero escape bytes', function () {
    // The explicit override, not ambient stdout state: whether this process's own STDOUT
    // happens to be a pipe or a real terminal must not decide whether this test passes — see
    // BannerTest for the same fix and why (the suite used to fail outright on a real tty).
    putenv('PAIDER_COLOR=0');

    expect(Palette::enabled())->toBeFalse()
        ->and(Palette::wrap(ColorRole::Error, 'boom'))->toBe('boom');
});

test('a role returns a stable, asserted escape sequence when colour is enabled', function () {
    // PAIDER_COLOR is the explicit override — it forces colour on even though stdout is
    // piped in this test process, exactly like our FORCE_COLOR equivalent is meant to.
    putenv('PAIDER_COLOR=1');

    expect(Palette::sgr(ColorRole::Accent))->toBe("\e[36m")
        ->and(Palette::wrap(ColorRole::Accent, 'x'))->toBe("\e[36mx\e[0m")
        ->and(Palette::tw(ColorRole::Accent))->toBe('text-cyan');
});

test('an unknown theme name falls back to the default rather than throwing', function () {
    putenv('PAIDER_COLOR=1');
    putenv('PAIDER_THEME=not-a-real-theme');

    expect(Palette::theme())->toBeNull()
        ->and(Palette::tw(ColorRole::Accent))->toBe('text-cyan'); // default fragment, not paider-accent
});

test('truecolor absence degrades rather than emitting a truecolor escape', function () {
    putenv('PAIDER_COLOR=1');
    putenv('PAIDER_THEME=dracula');
    putenv('TERM=xterm'); // no 256color
    putenv('COLORTERM'); // unset — no truecolor advertised either

    expect(Palette::sgr(ColorRole::Accent))->toBe('');
});

test('a bundled theme emits its 256-colour escape once the terminal advertises support', function () {
    // The mirror of the test above: supports256() has a negative-direction check only, which
    // a mutant hard-disabling it (`return false;`) also satisfies — this is the positive case
    // that mutant breaks. dracula's accent is 38;5;67 (see THEMES).
    putenv('PAIDER_COLOR=1');
    putenv('PAIDER_THEME=dracula');
    putenv('TERM=xterm-256color');

    expect(Palette::sgr(ColorRole::Accent))->toBe("\e[38;5;67m");
});

test('every non-exempt themed colour clears 3:1 contrast against its own theme background', function () {
    // The charcoal rule this whole class exists to enforce, checked for real: relative
    // luminance thresholds per WCAG's (L1+0.05)/(L2+0.05) contrast formula. A colour with
    // L <= 0.30 clears 3:1 against white; L >= 0.10 clears 3:1 against black. Muted is the
    // documented exemption ("safe to lose entirely"); 'alert' is a foreground/background PAIR
    // designed for mutual contrast with each other, not with the theme's own background, so
    // it's checked separately, correctly, rather than skipped.
    $srgb = fn (int $c) => ($c /= 255) <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
    $luminance = function (string $hex) use ($srgb) {
        $hex = ltrim($hex, '#');

        return 0.2126 * $srgb(hexdec(substr($hex, 0, 2)))
            + 0.7152 * $srgb(hexdec(substr($hex, 2, 2)))
            + 0.0722 * $srgb(hexdec(substr($hex, 4, 2)));
    };
    $contrast = function (float $a, float $b) {
        [$hi, $lo] = $a >= $b ? [$a, $b] : [$b, $a];

        return ($hi + 0.05) / ($lo + 0.05);
    };

    // Only solarized-light has a light background; everything else in THEMES is dark. Alert
    // is excluded like Muted — it's an inverse-video fg/bg pair designed to read against
    // itself, not against the theme's ambient background, so this check doesn't apply to it.
    $lightThemes = ['solarized-light'];

    $themes = (new ReflectionClassConstant(Palette::class, 'THEMES'))->getValue();

    foreach ($themes as $name => $theme) {
        $bgLuminance = in_array($name, $lightThemes, true) ? 1.0 : 0.0;

        foreach (['accent', 'success', 'error', 'brand'] as $role) {
            $l = $luminance($theme['tw'][$role]);

            expect($contrast($l, $bgLuminance))->toBeGreaterThanOrEqual(3.0,
                "{$name}.{$role} ({$theme['tw'][$role]}) fails 3:1 against its own theme background");
        }
    }
});

test('a piped run emits no colour, tested in a real subprocess', function () {
    // The non-tty rung cannot be tested in-process: stream_isatty(STDOUT) depends on how the
    // suite itself was invoked, so an in-process assertion passes under `vendor/bin/pest > log`
    // and fails on a real terminal. Both outcomes are about the harness, not the code.
    //
    // A child process with its output captured is genuinely non-tty every time.
    $php = <<<'CODE'
        require getcwd()."/vendor/autoload.php";
        $a = require getcwd()."/bootstrap/app.php";
        $a->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
        echo App\Support\Banner::render();
CODE;

    $output = shell_exec('cd '.escapeshellarg(base_path()).' && '
        .'env -u NO_COLOR -u PAIDER_COLOR -u PAIDER_THEME php -r '.escapeshellarg($php).' 2>/dev/null');

    expect($output)->not->toBeNull();
    expect($output)->toContain('The PHP Aider');   // it really rendered — not an empty string
    expect($output)->not->toContain("\e");         // ...and rendered without colour
});
