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
        ->toContain('meta/muse-spark-1.2-contributor');
});
