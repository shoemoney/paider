<?php

use App\Storage\Database;
use App\Storage\EventLog;
use Illuminate\Console\OutputStyle;
use LaravelZero\Framework\Kernel;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

beforeEach(function () {
    $this->originalCwd = getcwd();
    $this->tempCwd = sys_get_temp_dir().'/paider-cost-test-'.uniqid('', true);
    mkdir($this->tempCwd);
    chdir($this->tempCwd);
});

afterEach(function () {
    chdir($this->originalCwd);

    $db = $this->tempCwd.'/.paider/paider.db';
    foreach ([$db, $db.'-wal', $db.'-shm'] as $file) {
        if (is_file($file)) {
            unlink($file);
        }
    }
    if (is_dir($this->tempCwd.'/.paider')) {
        rmdir($this->tempCwd.'/.paider');
    }
    rmdir($this->tempCwd);
});

it('shows per-tier calls, tokens, and spend plus a session total', function () {
    $log = new EventLog(Database::connect());

    $log->append('tier_call', ['tier' => 'orchestrator', 'tokens_in' => 1000, 'tokens_out' => 200, 'cost_usd' => 0.45]);
    $log->append('tier_call', ['tier' => 'coder', 'tokens_in' => 20000, 'tokens_out' => 5000, 'cost_usd' => 0.03]);
    $log->append('note', ['text' => 'ignore me']);

    // Termwind's render() writes through the OutputInterface Laravel Zero's
    // Kernel::call() wires it to, not through Artisan's own captured buffer,
    // so expectsOutputToContain() can't see it. Drive the kernel directly.
    $bufferedOutput = new BufferedOutput;
    $outputStyle = new OutputStyle(new ArrayInput([]), $bufferedOutput);

    $exitCode = $this->app->make(Kernel::class)->call('cost', [], $outputStyle);

    expect($exitCode)->toBe(0);

    $output = $bufferedOutput->fetch();

    expect($output)
        ->toContain('orchestrator')
        ->toContain('coder')
        ->toContain('session')
        ->toContain('$0.450')
        ->toContain('$0.030')
        ->toContain('$0.480');
});

it('names the unpriced model when a call has no cost_usd', function () {
    $log = new EventLog(Database::connect());

    $log->append('tier_call', ['tier' => 'coder', 'model' => 'nobody/knows-this-model', 'tokens_in' => 500, 'tokens_out' => 100, 'cost_usd' => null]);

    $bufferedOutput = new BufferedOutput;
    $outputStyle = new OutputStyle(new ArrayInput([]), $bufferedOutput);

    $exitCode = $this->app->make(Kernel::class)->call('cost', [], $outputStyle);

    expect($exitCode)->toBe(0);

    $output = $bufferedOutput->fetch();

    expect($output)
        ->toContain('spend excludes 1 unpriced call')
        ->toContain('nobody/knows-this-model');
});

it('marks an unpriced tier row instead of printing a bare $0.000', function () {
    $log = new EventLog(Database::connect());

    $log->append('tier_call', ['tier' => 'orchestrator', 'tokens_in' => 1000, 'tokens_out' => 200, 'cost_usd' => 0.801]);
    $log->append('tier_call', ['tier' => 'coder', 'model' => 'nobody/knows-this-model', 'tokens_in' => 1_400_000, 'tokens_out' => 287_100, 'cost_usd' => null]);

    $bufferedOutput = new BufferedOutput;
    $outputStyle = new OutputStyle(new ArrayInput([]), $bufferedOutput);

    $exitCode = $this->app->make(Kernel::class)->call('cost', [], $outputStyle);

    expect($exitCode)->toBe(0);

    $output = $bufferedOutput->fetch();

    // The coder row is entirely unpriced, so its spend cell must be marked, not a bare $0.000
    // that reads as a real measured zero. Match on the unmarked form specifically — the marked
    // form '$0.000*' is a valid substring superset of it.
    expect(preg_match('/\$0\.000(?!\*)/', $output))->toBe(0);
    expect($output)->toContain('$0.000*');

    // The session total silently omits the unpriced coder spend too, so it must carry the
    // same marker rather than reading as a complete $0.801.
    expect($output)->toContain('$0.801*');
});

it('shows a no-usage message and exits successfully when the ledger is empty', function () {
    $bufferedOutput = new BufferedOutput;
    $outputStyle = new OutputStyle(new ArrayInput([]), $bufferedOutput);

    $exitCode = $this->app->make(Kernel::class)->call('cost', [], $outputStyle);

    expect($exitCode)->toBe(0);

    $output = $bufferedOutput->fetch();

    expect($output)->toContain('no usage recorded yet');
});
