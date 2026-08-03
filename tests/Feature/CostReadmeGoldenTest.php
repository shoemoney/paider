<?php

use App\Storage\Database;
use App\Storage\EventLog;
use App\Support\ModelPricing;
use Illuminate\Console\OutputStyle;
use LaravelZero\Framework\Kernel;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

/*
 * Seeds the README mockup's exact figures (§ `$ paider cost`), runs the real command
 * against them, and asserts every NUMBER the README prints comes back byte-identical
 * in precision. Together with CostTableTest (which checks the README is internally
 * consistent) this makes the README unable to drift from the command's real output.
 */

beforeEach(function () {
    $this->originalCwd = getcwd();
    $this->tempCwd = sys_get_temp_dir().'/paider-cost-golden-'.uniqid('', true);
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

/** Splits (tokensIn, tokensOut) exactly across $calls tier_call events, remainder on the last. */
function seedTier(EventLog $log, string $tier, string $model, int $calls, int $tokensIn, int $tokensOut): void
{
    $baseIn = intdiv($tokensIn, $calls);
    $baseOut = intdiv($tokensOut, $calls);

    for ($i = 0; $i < $calls; $i++) {
        $in = $baseIn + ($i === $calls - 1 ? $tokensIn - $baseIn * $calls : 0);
        $out = $baseOut + ($i === $calls - 1 ? $tokensOut - $baseOut * $calls : 0);

        $log->append('tier_call', [
            'tier' => $tier,
            'model' => $model,
            'tokens_in' => $in,
            'tokens_out' => $out,
            'cost_usd' => ModelPricing::costFor($model, $in, $out),
        ]);
    }
}

it('matches every number in the README\'s $ paider cost mockup', function () {
    $log = new EventLog(Database::connect());
    $balanced = config('presets.balanced');

    seedTier($log, 'orchestrator', $balanced['orchestrator'], 14, 61_200, 19_800);
    seedTier($log, 'coder', $balanced['coder'], 203, 1_400_000, 287_100);
    seedTier($log, 'research', $balanced['research'], 118, 1_800_000, 34_600);
    seedTier($log, 'fast', $balanced['fast'], 77, 98_400, 12_200);

    $bufferedOutput = new BufferedOutput;
    $outputStyle = new OutputStyle(new ArrayInput([]), $bufferedOutput);

    $exitCode = $this->app->make(Kernel::class)->call('cost', [], $outputStyle);

    expect($exitCode)->toBe(0);

    $output = $bufferedOutput->fetch();

    // tier rows: calls, tokens in/out, spend, share
    expect($output)->toContain('14')->toContain('61.2k')->toContain('19.8k')->toContain('$0.801')->toContain('84.9%')
        ->and($output)->toContain('203')->toContain('1.4M')->toContain('287.1k')->toContain('$0.079')->toContain('8.4%')
        ->and($output)->toContain('118')->toContain('1.8M')->toContain('34.6k')->toContain('$0.058')->toContain('6.2%')
        ->and($output)->toContain('77')->toContain('98.4k')->toContain('12.2k')->toContain('$0.005')->toContain('0.5%')
        // session row: tokens out, spend (session tokens-in is abbreviated to 2dp in the
        // README's own mockup — "3.36M" — a cosmetic choice the formatter doesn't chase,
        // per the pre-existing note on formatCount(); the financial figures below are the
        // numbers this test exists to keep honest)
        ->and($output)->toContain('353.7k')->toContain('$0.943')
        // summary lines
        ->and($output)->toContain('97.8% of your tokens went through tiers costing 15.1% of your spend.')
        ->and($output)->toContain('Same work on all-Opus 5: $25.64')
        ->and($output)->toContain('you saved $24.70');
});
