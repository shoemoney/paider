<?php

use App\Storage\ProjectEnv;
use App\Support\Palette;
use App\Support\TempestHighlighter;

/** @var array<string, string|false> */
$envSnapshot = [];

beforeEach(function () use (&$envSnapshot) {
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

test('a php fence highlights with SGR escapes when colour is enabled, and returns the input unchanged when it is not', function () {
    putenv('PAIDER_COLOR=1');
    Palette::forget();

    $code = '<?php echo "hi";';
    $highlighted = (new TempestHighlighter)->highlight($code, 'php');

    expect($highlighted)->toContain("\e[")
        ->and($highlighted)->not->toBe($code);

    putenv('PAIDER_COLOR=0');
    Palette::forget();

    expect((new TempestHighlighter)->highlight($code, 'php'))->toBe($code);
});

test('go, rust, diff and an unknown or empty tag all pass through unchanged, even with colour enabled', function () {
    putenv('PAIDER_COLOR=1');
    Palette::forget();

    $code = "some source text\nline two";
    $highlighter = new TempestHighlighter;

    foreach (['go', 'rust', 'diff', 'nonsense', ''] as $language) {
        expect($highlighter->highlight($code, $language))->toBe($code);
    }
});

test('a VALUE token (string literal) never emits SGR 30 — literal ANSI black is invisible on a dark terminal', function () {
    putenv('PAIDER_COLOR=1');
    Palette::forget();

    $highlighted = (new TempestHighlighter)->highlight("<?php\n\$name = 'jeremy';", 'php');

    expect($highlighted)->not->toContain("\e[30m");
});
