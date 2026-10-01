<?php

use App\Support\SettingsStore;
use Illuminate\Console\OutputStyle;
use LaravelZero\Framework\Kernel;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

beforeEach(function () {
    $this->originalCwd = getcwd();
    $this->tempCwd = sys_get_temp_dir().'/paider-config-show-test-'.uniqid('', true);
    mkdir($this->tempCwd);
    chdir($this->tempCwd);
});

afterEach(function () {
    chdir($this->originalCwd);

    $settings = $this->tempCwd.'/.paider/settings.json';
    if (is_file($settings)) {
        unlink($settings);
    }
    if (is_dir($this->tempCwd.'/.paider')) {
        rmdir($this->tempCwd.'/.paider');
    }
    rmdir($this->tempCwd);
});

it('shows all four tiers with the resolved model for the active preset', function () {
    SettingsStore::setActivePreset('balanced');

    // Termwind's render() writes through the OutputInterface Laravel Zero's
    // Kernel::call() wires it to, not through Artisan's own captured buffer,
    // so expectsOutputToContain() can't see it. Drive the kernel directly
    // with a real BufferedOutput to capture what actually gets rendered.
    $bufferedOutput = new BufferedOutput;
    $outputStyle = new OutputStyle(new ArrayInput([]), $bufferedOutput);

    $exitCode = $this->app->make(Kernel::class)->call('config:show', [], $outputStyle);

    expect($exitCode)->toBe(0);

    $output = $bufferedOutput->fetch();

    expect($output)
        ->toContain('orchestrator')
        ->toContain('coder')
        ->toContain('research')
        ->toContain('fast')
        ->toContain('anthropic/claude-opus-5')
        ->toContain('meta/muse-spark-1.2');
});

it('labels the two price columns instead of printing an ambiguous "a / b"', function () {
    // `paider config:show` printed one column headed "price" containing "$5.00 / $25.00", scraped
    // from a `// $in / $out` comment in config/presets.php. Nothing in the output said which was
    // input and which was output — and on a table whose whole purpose is choosing a model by
    // cost, guessing wrong is expensive. Found by a blind visual review of a surface this loop
    // had never judged before.
    $source = (string) file_get_contents(base_path('app/Commands/Config/ShowCommand.php'));

    expect($source)->toContain('in / 1M')
        ->and($source)->toContain('out / 1M')
        // The unit is part of the meaning: "$5.00" alone is a number, not a rate.
        ->and($source)->toContain("return ['in' => \$m[1], 'out' => \$m[2]];");

    // And it must no longer be reachable as one ambiguous cell.
    expect($source)->not->toContain('<th class="px-1">price</th>');
});

it('right-aligns both price columns so magnitudes compare down the column', function () {
    $source = (string) file_get_contents(base_path('app/Commands/Config/ShowCommand.php'));

    // Same trap as the cost table: `text-right` compiles cleanly and renders identically,
    // because TableRenderer reads the align ATTRIBUTE. Assert the attribute.
    expect(substr_count($source, 'align="right"'))->toBeGreaterThanOrEqual(2);
});
