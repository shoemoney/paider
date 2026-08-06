<?php

use App\Storage\Database;
use App\Storage\EventLog;
use Illuminate\Console\OutputStyle;
use LaravelZero\Framework\Kernel;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

/*
 * Pins the exact key set of `paider cost --json` — top level, per-tier rows, the
 * session row, unpriced_calls entries, and the comparison block. Uses exact-set
 * comparisons (array_keys()->toEqualCanonicalizing()), never toHaveKeys() — that
 * only checks a subset and would silently pass an added, removed, or renamed key.
 */

beforeEach(function () {
    $this->originalCwd = getcwd();
    $this->tempCwd = sys_get_temp_dir().'/paider-cost-json-golden-'.uniqid('', true);
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

function costJsonKeySets(): array
{
    return [
        // 'model_mismatches' arrived with the served-vs-requested-model work: providers can
        // serve a different model than you asked for, and the ledger surfaces that rather than
        // silently pricing the id you requested. This pin caught the addition on merge, which
        // is exactly what it is for — the key is intentional, so the pin moves with it.
        'top' => ['tiers', 'session', 'unpriced_calls', 'comparison', 'model_mismatches'],
        // 'cache_*' arrived with the response-cache ledger semantics. Note the deliberate
        // naming: the comparison block already has a 'saved_usd' meaning "what tier routing
        // saved against all-Opus", which is a different quantity from what the response cache
        // saved. Two things called saved_usd in one payload would be read as one number.
        'tier' => ['calls', 'tokens_in', 'tokens_out', 'tokens_cache_write', 'tokens_cache_read', 'spend_usd', 'unpriced_calls', 'unpriced_models', 'hypothetical_usd', 'hypothetical_unknown', 'share_pct', 'mismatched_calls', 'mismatched_models', 'cache_hits', 'cache_saved_usd', 'cache_unpriced_hits'],
        'session' => ['calls', 'tokens_in', 'tokens_out', 'tokens_cache_write', 'tokens_cache_read', 'spend_usd', 'unpriced_calls', 'unpriced_models', 'hypothetical_usd', 'hypothetical_unknown', 'mismatched_calls', 'mismatched_models', 'cache_hits', 'cache_saved_usd', 'cache_unpriced_hits'],
        'unpriced_entry' => ['tier', 'count', 'calls', 'models'],
        'comparison' => ['hypothetical_usd', 'saved_usd', 'token_share_pct', 'spend_share_pct'],
    ];
}

it('pins the exact --json key structure on a seeded ledger with a priced and an unpriced call', function () {
    $log = new EventLog(Database::connect());

    $log->append('tier_call', [
        'tier' => 'orchestrator', 'tokens_in' => 1000, 'tokens_out' => 200,
        'cost_usd' => 0.75, 'hypothetical_usd' => 0.75,
    ]);
    $log->append('tier_call', [
        'tier' => 'coder', 'model' => 'nobody/knows-this-model',
        'tokens_in' => 500, 'tokens_out' => 100, 'cost_usd' => null,
    ]);

    $bufferedOutput = new BufferedOutput;
    $outputStyle = new OutputStyle(new ArrayInput([]), $bufferedOutput);

    $exitCode = $this->app->make(Kernel::class)->call('cost', ['--json' => true], $outputStyle);
    expect($exitCode)->toBe(0);

    $data = json_decode($bufferedOutput->fetch(), true, 512, JSON_THROW_ON_ERROR);
    $keys = costJsonKeySets();

    expect(array_keys($data))->toEqualCanonicalizing($keys['top'])
        ->and(array_keys($data['tiers']['orchestrator']))->toEqualCanonicalizing($keys['tier'])
        ->and(array_keys($data['tiers']['coder']))->toEqualCanonicalizing($keys['tier'])
        ->and(array_keys($data['session']))->toEqualCanonicalizing($keys['session'])
        ->and(array_keys($data['unpriced_calls'][0]))->toEqualCanonicalizing($keys['unpriced_entry'])
        ->and(array_keys($data['comparison']))->toEqualCanonicalizing($keys['comparison']);
});

it('still emits the pinned --json key structure, with real zeroes, on an empty ledger', function () {
    $bufferedOutput = new BufferedOutput;
    $outputStyle = new OutputStyle(new ArrayInput([]), $bufferedOutput);

    $exitCode = $this->app->make(Kernel::class)->call('cost', ['--json' => true], $outputStyle);
    expect($exitCode)->toBe(0);

    $data = json_decode($bufferedOutput->fetch(), true, 512, JSON_THROW_ON_ERROR);
    $keys = costJsonKeySets();

    expect(array_keys($data))->toEqualCanonicalizing($keys['top'])
        ->and($data['tiers'])->toBe([])
        ->and(array_keys($data['session']))->toEqualCanonicalizing($keys['session'])
        ->and($data['session']['calls'])->toBe(0)
        ->and((float) $data['session']['spend_usd'])->toBe(0.0)
        ->and($data['unpriced_calls'])->toBe([])
        ->and(array_keys($data['comparison']))->toEqualCanonicalizing($keys['comparison'])
        ->and($data['comparison']['token_share_pct'])->toBeNull()
        ->and($data['comparison']['spend_share_pct'])->toBeNull();
});
