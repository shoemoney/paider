<?php

use App\Storage\Database;
use App\Storage\EventLog;
use App\Support\ModelPricing;
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
        ->toContain('1 of 1 coder calls not priced')
        ->toContain('nobody/knows-this-model')
        ->toContain('totals exclude them');
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

    expect($output)
        ->toContain('no usage recorded yet')
        ->toContain('paider chat')
        ->toContain('paider commit');
});

it('computes each tier\'s share of session spend to one decimal place', function () {
    $log = new EventLog(Database::connect());

    $log->append('tier_call', ['tier' => 'orchestrator', 'tokens_in' => 1000, 'tokens_out' => 200, 'cost_usd' => 0.75]);
    $log->append('tier_call', ['tier' => 'coder', 'tokens_in' => 20000, 'tokens_out' => 5000, 'cost_usd' => 0.25]);

    $bufferedOutput = new BufferedOutput;
    $outputStyle = new OutputStyle(new ArrayInput([]), $bufferedOutput);

    $exitCode = $this->app->make(Kernel::class)->call('cost', [], $outputStyle);

    expect($exitCode)->toBe(0);

    $output = $bufferedOutput->fetch();

    // 0.75 / 1.00 = 75.0%, 0.25 / 1.00 = 25.0%
    expect($output)->toContain('75.0%')->toContain('25.0%');
});

it('renders share as a dash and skips both summary lines when session spend is 0', function () {
    $log = new EventLog(Database::connect());

    $log->append('tier_call', ['tier' => 'coder', 'model' => 'nobody/knows-this-model', 'tokens_in' => 500, 'tokens_out' => 100, 'cost_usd' => null]);

    $bufferedOutput = new BufferedOutput;
    $outputStyle = new OutputStyle(new ArrayInput([]), $bufferedOutput);

    $exitCode = $this->app->make(Kernel::class)->call('cost', [], $outputStyle);

    expect($exitCode)->toBe(0);

    $output = $bufferedOutput->fetch();

    expect($output)
        ->toContain('—')
        ->not->toContain('of your tokens went through')
        ->not->toContain('Same work on all-Opus 5');
});

it('marks the session aggregate row\'s calls and share cells with a dash instead of leaving them blank', function () {
    $log = new EventLog(Database::connect());

    $log->append('tier_call', ['tier' => 'orchestrator', 'tokens_in' => 1000, 'tokens_out' => 200, 'cost_usd' => 0.45]);
    $log->append('tier_call', ['tier' => 'coder', 'tokens_in' => 20000, 'tokens_out' => 5000, 'cost_usd' => 0.03]);

    $bufferedOutput = new BufferedOutput;
    $outputStyle = new OutputStyle(new ArrayInput([]), $bufferedOutput);

    $exitCode = $this->app->make(Kernel::class)->call('cost', [], $outputStyle);
    expect($exitCode)->toBe(0);

    $output = $bufferedOutput->fetch();

    // Blank cells are indistinguishable from missing data at a glance; the session row's
    // own calls/share are meaningless (it's the whole, not a fraction of itself) and must
    // say so rather than render as empty table cells. Termwind renders the table as plain
    // text, not raw HTML, so match the rendered line containing "session" (the last row).
    $lines = array_values(array_filter(explode("\n", $output)));
    $sessionLine = '';
    foreach ($lines as $line) {
        if (str_contains($line, 'session')) {
            $sessionLine = $line;
        }
    }

    expect($sessionLine)->toContain('—');
    // A genuinely broken fix (still rendering '' for calls/share) would leave the session
    // line with only one dash cell (the pre-existing share fallback) or none at all --
    // require both non-applicable cells to carry the marker.
    expect(substr_count($sessionLine, '—'))->toBeGreaterThanOrEqual(2);
});

it('prints the total spend above the table, ahead of the session aggregate row', function () {
    $log = new EventLog(Database::connect());

    $log->append('tier_call', ['tier' => 'orchestrator', 'tokens_in' => 1000, 'tokens_out' => 200, 'cost_usd' => 0.45]);
    $log->append('tier_call', ['tier' => 'coder', 'tokens_in' => 20000, 'tokens_out' => 5000, 'cost_usd' => 0.03]);

    $bufferedOutput = new BufferedOutput;
    $outputStyle = new OutputStyle(new ArrayInput([]), $bufferedOutput);

    $exitCode = $this->app->make(Kernel::class)->call('cost', [], $outputStyle);
    expect($exitCode)->toBe(0);

    $output = $bufferedOutput->fetch();

    expect($output)->toContain('Total spend: $0.480');

    // Must appear before the table's own content (the tier name is the earliest table
    // marker available in rendered output), not buried after it or after the summary
    // lines like the pre-fix layout did.
    $totalPos = strpos($output, 'Total spend');
    $tablePos = strpos($output, 'orchestrator');

    expect($totalPos)->not->toBeFalse()
        ->and($tablePos)->not->toBeFalse()
        ->and($totalPos)->toBeLessThan($tablePos);
});

it('marks the total spend line and the session aggregate row with the same unpriced marker', function () {
    $log = new EventLog(Database::connect());

    $log->append('tier_call', ['tier' => 'orchestrator', 'tokens_in' => 1000, 'tokens_out' => 200, 'cost_usd' => 0.801]);
    $log->append('tier_call', ['tier' => 'coder', 'model' => 'nobody/knows-this-model', 'tokens_in' => 1_400_000, 'tokens_out' => 287_100, 'cost_usd' => null]);

    $bufferedOutput = new BufferedOutput;
    $outputStyle = new OutputStyle(new ArrayInput([]), $bufferedOutput);

    $exitCode = $this->app->make(Kernel::class)->call('cost', [], $outputStyle);
    expect($exitCode)->toBe(0);

    $output = $bufferedOutput->fetch();

    expect($output)->toContain('Total spend: $0.801*');
});

it('emits the same computed arrays as JSON with --json', function () {
    $log = new EventLog(Database::connect());

    $log->append('tier_call', ['tier' => 'orchestrator', 'tokens_in' => 1000, 'tokens_out' => 200, 'cost_usd' => 0.75]);
    $log->append('tier_call', ['tier' => 'coder', 'model' => 'nobody/knows-this-model', 'tokens_in' => 500, 'tokens_out' => 100, 'cost_usd' => null]);

    $bufferedOutput = new BufferedOutput;
    $outputStyle = new OutputStyle(new ArrayInput([]), $bufferedOutput);

    $exitCode = $this->app->make(Kernel::class)->call('cost', ['--json' => true], $outputStyle);

    expect($exitCode)->toBe(0);

    $data = json_decode($bufferedOutput->fetch(), true, 512, JSON_THROW_ON_ERROR);

    expect($data)->toHaveKeys(['tiers', 'session', 'unpriced_calls', 'comparison'])
        ->and($data['tiers']['orchestrator']['spend_usd'])->toBe(0.75)
        // The session has an unpriced call (in the coder tier), so spend_usd is known
        // to undercount the true total -- EVERY row's share_pct is null, not just the
        // unpriced row's, per the fix for the inverted-ratio bug (finding 11).
        ->and($data['tiers']['orchestrator']['share_pct'])->toBeNull()
        ->and($data['tiers']['coder']['share_pct'])->toBeNull()
        ->and($data['tiers']['coder']['unpriced_calls'])->toBe(1)
        ->and($data['unpriced_calls'][0])->toBe(['tier' => 'coder', 'count' => 1, 'calls' => 1, 'models' => ['nobody/knows-this-model']])
        ->and($data['session']['spend_usd'])->toBe(0.75)
        ->and($data['comparison'])->toHaveKeys(['hypothetical_usd', 'saved_usd', 'token_share_pct', 'spend_share_pct']);
});

it('emits well-formed JSON with real zeroes, not human prose, when --json is passed on an empty ledger', function () {
    $bufferedOutput = new BufferedOutput;
    $outputStyle = new OutputStyle(new ArrayInput([]), $bufferedOutput);

    $exitCode = $this->app->make(Kernel::class)->call('cost', ['--json' => true], $outputStyle);

    expect($exitCode)->toBe(0);

    $data = json_decode($bufferedOutput->fetch(), true, 512, JSON_THROW_ON_ERROR);

    expect($data)->toHaveKeys(['tiers', 'session', 'unpriced_calls', 'comparison'])
        ->and($data['tiers'])->toBe([])
        ->and($data['session']['calls'])->toBe(0)
        // json_encode(0.0) round-trips through json_decode() as the int 0, not 0.0 --
        // a JSON quirk, not a cost-precision bug (checked with (float) below too).
        ->and((float) $data['session']['spend_usd'])->toBe(0.0)
        ->and($data['unpriced_calls'])->toBe([]);
});

it('reports a mixed tier\'s unpriced count and total call count without swapping them', function () {
    $log = new EventLog(Database::connect());

    for ($i = 0; $i < 3; $i++) {
        $log->append('tier_call', ['tier' => 'coder', 'model' => 'anthropic/claude-haiku-4.5', 'tokens_in' => 1000, 'tokens_out' => 200, 'cost_usd' => 0.006]);
    }
    for ($i = 0; $i < 2; $i++) {
        $log->append('tier_call', ['tier' => 'coder', 'model' => 'nobody/knows-this-model', 'tokens_in' => 500, 'tokens_out' => 100, 'cost_usd' => null]);
    }

    $bufferedOutput = new BufferedOutput;
    $outputStyle = new OutputStyle(new ArrayInput([]), $bufferedOutput);

    $exitCode = $this->app->make(Kernel::class)->call('cost', [], $outputStyle);
    expect($exitCode)->toBe(0);
    // "2 of 5", never "5 of 2" -- a count/calls swap is invisible unless the tier is
    // genuinely mixed (some priced, some not), which is exactly this fixture.
    expect($bufferedOutput->fetch())->toContain('2 of 5 coder calls not priced');

    $jsonOutput = new BufferedOutput;
    $jsonStyle = new OutputStyle(new ArrayInput([]), $jsonOutput);
    $this->app->make(Kernel::class)->call('cost', ['--json' => true], $jsonStyle);
    $data = json_decode($jsonOutput->fetch(), true, 512, JSON_THROW_ON_ERROR);

    expect($data['unpriced_calls'][0])->toBe(['tier' => 'coder', 'count' => 2, 'calls' => 5, 'models' => ['nobody/knows-this-model']]);
});

it('never books a confident $0.000 for zero tokens on a priced model -- a completion is never free', function () {
    config(['prices' => ['anthropic/claude-opus-5' => ['in' => 5.00, 'out' => 25.00]]]);

    $log = new EventLog(Database::connect());
    // Simulates a provider response whose usage block was never parsed (differently
    // keyed field, or a 200-with-error body): tokens_in/out both 0 on a model that IS
    // in config/prices.php, so ModelPricing::costFor() must return null, not $0.00.
    $log->append('tier_call', [
        'tier' => 'orchestrator', 'model' => 'anthropic/claude-opus-5',
        'tokens_in' => 0, 'tokens_out' => 0,
        'cost_usd' => ModelPricing::costFor('anthropic/claude-opus-5', 0, 0),
    ]);

    $bufferedOutput = new BufferedOutput;
    $outputStyle = new OutputStyle(new ArrayInput([]), $bufferedOutput);

    $exitCode = $this->app->make(Kernel::class)->call('cost', [], $outputStyle);
    expect($exitCode)->toBe(0);

    $output = $bufferedOutput->fetch();

    expect(preg_match('/\$0\.000(?!\*)/', $output))->toBe(0);
    expect($output)->toContain('$0.000*');
    // Names WHAT happened -- a known model with no reported usage -- not the wrong
    // "unknown model" explanation that only fits a model absent from prices.php.
    expect($output)->toContain('no usage reported: anthropic/claude-opus-5');
    expect($output)->not->toContain('unknown model: anthropic/claude-opus-5');
});

it('freezes the all-Opus hypothetical at write time -- a later config/prices.php edit cannot move it', function () {
    $log = new EventLog(Database::connect());

    $model = ModelPricing::REFERENCE_MODEL;
    $tokensIn = 1_000_000;
    $tokensOut = 200_000;

    // A session that ran 100% on the reference model itself: cost_usd and
    // hypothetical_usd are computed identically, at write time, exactly as
    // Loop.php/CommitCommand.php do.
    $log->append('tier_call', [
        'tier' => 'orchestrator', 'model' => $model,
        'tokens_in' => $tokensIn, 'tokens_out' => $tokensOut,
        'cost_usd' => ModelPricing::costFor($model, $tokensIn, $tokensOut),
        'hypothetical_usd' => ModelPricing::costFor($model, $tokensIn, $tokensOut),
    ]);

    // A price CUT after the fact must not move either side of the comparison --
    // both were already frozen. Under read-time re-pricing this would print a
    // logically impossible negative saving against the very model that ran.
    config(['prices.'.$model => ['in' => 0.01, 'out' => 0.01]]);

    $bufferedOutput = new BufferedOutput;
    $outputStyle = new OutputStyle(new ArrayInput([]), $bufferedOutput);

    $this->app->make(Kernel::class)->call('cost', ['--json' => true], $outputStyle);
    $data = json_decode($bufferedOutput->fetch(), true, 512, JSON_THROW_ON_ERROR);

    expect((float) $data['comparison']['saved_usd'])->toBe(0.0)
        ->and($data['comparison']['hypothetical_usd'])->toBe($data['session']['spend_usd']);
});

it('suppresses the comparison line rather than mixing bases when a tier_call predates the hypothetical_usd field', function () {
    $log = new EventLog(Database::connect());

    // A legacy payload with no 'hypothetical_usd' key at all -- exactly what every
    // event written before this field existed looks like.
    $log->append('tier_call', ['tier' => 'orchestrator', 'tokens_in' => 1000, 'tokens_out' => 200, 'cost_usd' => 0.45]);

    $bufferedOutput = new BufferedOutput;
    $outputStyle = new OutputStyle(new ArrayInput([]), $bufferedOutput);

    $exitCode = $this->app->make(Kernel::class)->call('cost', [], $outputStyle);
    expect($exitCode)->toBe(0);

    expect($bufferedOutput->fetch())->not->toContain('Same work on all-Opus 5');
});

it('clamps float residue so a near-zero saving never prints "you saved $-0.00"', function () {
    $log = new EventLog(Database::connect());

    // hypothetical_usd fractionally below spend_usd -- e.g. float residue from a
    // 100%-reference-model session -- must clamp to $0.00, not print a negative.
    $log->append('tier_call', [
        'tier' => 'orchestrator', 'model' => 'anthropic/claude-opus-5',
        'tokens_in' => 1000, 'tokens_out' => 200,
        'cost_usd' => 10.001, 'hypothetical_usd' => 10.000,
    ]);

    $bufferedOutput = new BufferedOutput;
    $outputStyle = new OutputStyle(new ArrayInput([]), $bufferedOutput);

    $exitCode = $this->app->make(Kernel::class)->call('cost', [], $outputStyle);
    expect($exitCode)->toBe(0);

    $output = $bufferedOutput->fetch();

    expect($output)->toContain('you saved $0.00')
        ->and($output)->not->toContain('$-0.00')
        ->and($output)->not->toContain('cost more');
});

it('names a served-vs-requested model mismatch in the table', function () {
    $log = new EventLog(Database::connect());

    $log->append('tier_call', [
        'tier' => 'orchestrator', 'model' => 'anthropic/claude-sonnet-5', 'requested_model' => 'anthropic/claude-opus-5',
        'tokens_in' => 1000, 'tokens_out' => 200, 'cost_usd' => 0.45,
    ]);

    $bufferedOutput = new BufferedOutput;
    $outputStyle = new OutputStyle(new ArrayInput([]), $bufferedOutput);

    $exitCode = $this->app->make(Kernel::class)->call('cost', [], $outputStyle);
    expect($exitCode)->toBe(0);

    $output = $bufferedOutput->fetch();

    expect($output)
        ->toContain('served a different model than requested')
        ->toContain('anthropic/claude-opus-5 -> anthropic/claude-sonnet-5');
});

it('surfaces model_mismatches in the --json payload', function () {
    $log = new EventLog(Database::connect());

    $log->append('tier_call', [
        'tier' => 'orchestrator', 'model' => 'anthropic/claude-sonnet-5', 'requested_model' => 'anthropic/claude-opus-5',
        'tokens_in' => 1000, 'tokens_out' => 200, 'cost_usd' => 0.45,
    ]);

    $bufferedOutput = new BufferedOutput;
    $outputStyle = new OutputStyle(new ArrayInput([]), $bufferedOutput);

    $exitCode = $this->app->make(Kernel::class)->call('cost', ['--json' => true], $outputStyle);
    expect($exitCode)->toBe(0);

    $data = json_decode($bufferedOutput->fetch(), true, 512, JSON_THROW_ON_ERROR);

    expect($data)->toHaveKey('model_mismatches')
        ->and($data['model_mismatches'])->toBe([[
            'tier' => 'orchestrator',
            'count' => 1,
            'calls' => 1,
            'models' => ['anthropic/claude-opus-5 -> anthropic/claude-sonnet-5'],
        ]]);
});

it('says a session cost more, rather than "saved" a negative number, when it ran pricier than the reference model', function () {
    $log = new EventLog(Database::connect());

    $log->append('tier_call', [
        'tier' => 'orchestrator', 'model' => 'openai/gpt-5.5-pro',
        'tokens_in' => 100_000, 'tokens_out' => 20_000,
        'cost_usd' => 6.6, 'hypothetical_usd' => 1.0,
    ]);

    $bufferedOutput = new BufferedOutput;
    $outputStyle = new OutputStyle(new ArrayInput([]), $bufferedOutput);

    $exitCode = $this->app->make(Kernel::class)->call('cost', [], $outputStyle);
    expect($exitCode)->toBe(0);

    $output = $bufferedOutput->fetch();

    expect($output)->toContain('this session cost $5.60 more')
        ->and($output)->not->toContain('you saved $-5.60');
});
