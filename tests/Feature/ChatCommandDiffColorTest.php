<?php

use App\Commands\ChatCommand;
use App\Storage\ProjectEnv;
use App\Support\Palette;

/**
 * colorizeDiff() is pure (string in, string out) — exercised via Reflection on the private
 * static method rather than through handleDiff(), which shells out to a real `git diff` and
 * echoes. Same approach LoopHighlightingTest takes for splitFences().
 */
function colorizeDiff(string $diff): string
{
    $method = new ReflectionMethod(ChatCommand::class, 'colorizeDiff');

    return $method->invoke(null, $diff);
}

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

test('an added line is coloured, with colour enabled', function () {
    putenv('PAIDER_COLOR=1');
    Palette::forget();

    $out = colorizeDiff('+$new = true;');

    expect($out)->toContain("\e[32m")
        ->and($out)->toContain('+$new = true;')
        ->and($out)->toContain("\e[0m");
});

test('a removed line is coloured, with colour enabled', function () {
    putenv('PAIDER_COLOR=1');
    Palette::forget();

    $out = colorizeDiff('-$old = true;');

    expect($out)->toContain("\e[31m")
        ->and($out)->toContain('-$old = true;');
});

test('a hunk header is coloured as a hunk header, not as an added/removed line', function () {
    putenv('PAIDER_COLOR=1');
    Palette::forget();

    $out = colorizeDiff('@@ -1,3 +1,4 @@');

    expect($out)->toContain("\e[36m")
        ->and($out)->not->toContain("\e[32m")
        ->and($out)->not->toContain("\e[31m");
});

test('+++ and --- file headers are coloured as headers, never as added/removed content', function () {
    putenv('PAIDER_COLOR=1');
    Palette::forget();

    $diff = "--- a/file.php\n+++ b/file.php";
    $out = colorizeDiff($diff);
    $lines = explode("\n", $out);

    // Muted (90), not Error (31) / Success (32) — the off-by-one this brief calls out by name.
    expect($lines[0])->toContain("\e[90m")->not->toContain("\e[31m");
    expect($lines[1])->toContain("\e[90m")->not->toContain("\e[32m");
});

test('handleDiff() itself — not just colorizeDiff() in isolation — sanitises the raw git diff before it reaches the terminal', function () {
    if (shell_exec('command -v git') === null) {
        $this->markTestSkipped('git not available');
    }

    putenv('PAIDER_COLOR=1');
    Palette::forget();

    $repo = sys_get_temp_dir().'/paider-diffcolor-'.uniqid();
    mkdir($repo);
    shell_exec('git -C '.escapeshellarg($repo).' init -q');
    shell_exec('git -C '.escapeshellarg($repo).' config user.email test@paider.test');
    shell_exec('git -C '.escapeshellarg($repo).' config user.name paidertest');
    file_put_contents($repo.'/f.txt', "line one\n");
    shell_exec('git -C '.escapeshellarg($repo).' add -A && git -C '.escapeshellarg($repo).' commit -qm init');
    // The OSC 8 hyperlink payload lands in tracked, uncommitted content — exactly what
    // `git diff` (no path filter) would print verbatim if handleDiff() skipped TerminalSafe.
    file_put_contents($repo.'/f.txt', "line one\x1b]8;;https://evil\x07hidden\n");

    $cwd = getcwd();
    chdir($repo);

    try {
        $command = new ChatCommand;
        $method = new ReflectionMethod(ChatCommand::class, 'handleDiff');

        ob_start();
        $method->invoke($command);
        $output = ob_get_clean();
    } finally {
        chdir($cwd);
        shell_exec('rm -rf '.escapeshellarg($repo));
    }

    expect($output)->not->toContain("\x1b]8");
});

test('with colour disabled, output is byte-identical to the raw diff', function () {
    putenv('PAIDER_COLOR=0');
    Palette::forget();

    $diff = "diff --git a/f b/f\n--- a/f\n+++ b/f\n@@ -1 +1 @@\n-old\n+new\n context";

    expect(colorizeDiff($diff))->toBe($diff);
});
