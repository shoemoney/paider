<?php

use App\Storage\CacheLedger;
use App\Storage\CostLedger;
use App\Storage\Database;
use App\Storage\EventLog;
use App\Support\ModelPricing;

function cacheLog(): EventLog
{
    return new EventLog(Database::connect(':memory:'));
}

test('a hit records a real saving, not null — the trap this whole design exists for', function () {
    // ModelPricing::costFor() returns NULL for 0-in/0-out/0-cache, deliberately (LOCKED #3).
    // A cache hit makes no provider call, so the naive event carries four zeros and the saving
    // evaporates as "unknown" on every hit — the ledger would report zero savings forever while
    // the feature's entire claim is that it saves money.
    expect(ModelPricing::costFor(ModelPricing::REFERENCE_MODEL, 0, 0, 0, 0))->toBeNull();

    $log = cacheLog();
    CacheLedger::recordHit($log, 'orchestrator', ModelPricing::REFERENCE_MODEL, 10_000, 2_000);

    $payload = array_values(array_filter($log->all(), fn ($e) => $e['type'] === CacheLedger::HIT))[0]['payload'];

    expect($payload['cache_saved_usd'])->not->toBeNull();
    expect($payload['cache_saved_usd'])->toBeGreaterThan(0.0);
});

test('the saving equals what the call would have cost', function () {
    $log = cacheLog();
    CacheLedger::recordHit($log, 'coder', ModelPricing::REFERENCE_MODEL, 10_000, 2_000, 500, 1_000);

    expect(array_values(array_filter($log->all(), fn ($e) => $e['type'] === CacheLedger::HIT))[0]['payload']['cache_saved_usd'])
        ->toBe(ModelPricing::costFor(ModelPricing::REFERENCE_MODEL, 10_000, 2_000, 500, 1_000));
});

test('the ledger sums savings per tier and for the session', function () {
    $log = cacheLog();
    CacheLedger::recordHit($log, 'orchestrator', ModelPricing::REFERENCE_MODEL, 10_000, 2_000);
    CacheLedger::recordHit($log, 'orchestrator', ModelPricing::REFERENCE_MODEL, 10_000, 2_000);
    CacheLedger::recordHit($log, 'research', ModelPricing::REFERENCE_MODEL, 1_000, 100);

    $summary = (new CostLedger($log))->summary();
    $one = ModelPricing::costFor(ModelPricing::REFERENCE_MODEL, 10_000, 2_000);

    expect($summary['orchestrator']['cache_hits'])->toBe(2);
    expect($summary['orchestrator']['cache_saved_usd'])->toBe($one * 2);
    expect($summary['research']['cache_hits'])->toBe(1);
    expect($summary['session']['cache_hits'])->toBe(3);
    expect($summary['session']['cache_saved_usd'])
        ->toBe($one * 2 + ModelPricing::costFor(ModelPricing::REFERENCE_MODEL, 1_000, 100));
});

test('a hit adds NOTHING to calls, spend or token volume', function () {
    // The credibility invariant. No provider call was made and nothing was billed, so a hit
    // that touched these would inflate the exact numbers the README's savings claim rests on.
    $log = cacheLog();
    CacheLedger::recordHit($log, 'orchestrator', ModelPricing::REFERENCE_MODEL, 10_000, 2_000, 50, 60);

    $tier = (new CostLedger($log))->summary()['orchestrator'];

    expect($tier['calls'])->toBe(0);
    expect($tier['spend_usd'])->toBe(0.0);
    expect($tier['tokens_in'])->toBe(0);
    expect($tier['tokens_out'])->toBe(0);
    expect($tier['tokens_cache_write'])->toBe(0);
    expect($tier['tokens_cache_read'])->toBe(0);
    expect($tier['hypothetical_usd'])->toBe(0.0);
});

test('savings are never applied as a discount to spend', function () {
    $log = cacheLog();
    $log->append('tier_call', [
        'tier' => 'orchestrator',
        'model' => ModelPricing::REFERENCE_MODEL,
        'requested_model' => ModelPricing::REFERENCE_MODEL,
        'tokens_in' => 1_000, 'tokens_out' => 200,
        'tokens_cache_write' => 0, 'tokens_cache_read' => 0,
        'cost_usd' => ModelPricing::costFor(ModelPricing::REFERENCE_MODEL, 1_000, 200),
        'hypothetical_usd' => ModelPricing::costFor(ModelPricing::REFERENCE_MODEL, 1_000, 200),
    ]);
    CacheLedger::recordHit($log, 'orchestrator', ModelPricing::REFERENCE_MODEL, 50_000, 9_000);

    $tier = (new CostLedger($log))->summary()['orchestrator'];

    // Spend is what was actually billed. A large saving sitting beside it must not reduce it,
    // and must certainly not drive it negative.
    expect($tier['spend_usd'])->toBe(ModelPricing::costFor(ModelPricing::REFERENCE_MODEL, 1_000, 200));
    expect($tier['calls'])->toBe(1);
    expect($tier['cache_hits'])->toBe(1);
});

test('an unpriced model records an unknown saving, never a confident zero', function () {
    $log = cacheLog();
    CacheLedger::recordHit($log, 'orchestrator', 'vendor/not-in-prices-file', 10_000, 2_000);

    expect(array_values(array_filter($log->all(), fn ($e) => $e['type'] === CacheLedger::HIT))[0]['payload']['cache_saved_usd'])->toBeNull();

    $tier = (new CostLedger($log))->summary()['orchestrator'];

    expect($tier['cache_hits'])->toBe(1);
    expect($tier['cache_unpriced_hits'])->toBe(1);
    // Not folded into the total: zero would understate savings while looking precise.
    expect($tier['cache_saved_usd'])->toBe(0.0);
});

test('the saving is frozen at write time, so a later price change cannot restate it', function () {
    $log = cacheLog();
    CacheLedger::recordHit($log, 'orchestrator', ModelPricing::REFERENCE_MODEL, 10_000, 2_000);
    $recorded = array_values(array_filter($log->all(), fn ($e) => $e['type'] === CacheLedger::HIT))[0]['payload']['cache_saved_usd'];

    // Same discipline as cost_usd (LOCKED #2): editing config/prices.php must not silently
    // rewrite savings already claimed in an earlier session.
    // The key is 'in', not 'input'. This test previously set 'input' — a key costFor() never
    // reads — so the price never actually moved and the assertion compared an unchanged value
    // to itself. It passed identically with the freeze REMOVED, which is the definition of a
    // test that cannot fail. Verified: with 'in', neutering the freeze turns this red.
    config()->set('prices.'.ModelPricing::REFERENCE_MODEL.'.in', 999.0);

    expect((new CostLedger($log))->summary()['orchestrator']['cache_saved_usd'])->toBe($recorded);
});

test('a hit for a tier with no calls still appears, rather than being dropped', function () {
    $log = cacheLog();
    CacheLedger::recordHit($log, 'fast', ModelPricing::REFERENCE_MODEL, 500, 50);

    expect((new CostLedger($log))->summary())->toHaveKey('fast');
});

test('a malformed hit is skipped rather than corrupting the summary', function () {
    $log = cacheLog();
    $log->append(CacheLedger::HIT, ['model' => 'x', 'cache_saved_usd' => 1.0]); // no tier
    CacheLedger::recordHit($log, 'coder', ModelPricing::REFERENCE_MODEL, 1_000, 100);

    $summary = (new CostLedger($log))->summary();

    expect($summary['session']['cache_hits'])->toBe(1);
    expect($summary)->not->toHaveKey('');
});

test('a project with no cache hits reports zero savings and zero unknowns', function () {
    $log = cacheLog();
    $log->append('tier_call', [
        'tier' => 'orchestrator',
        'model' => ModelPricing::REFERENCE_MODEL,
        'requested_model' => ModelPricing::REFERENCE_MODEL,
        'tokens_in' => 100, 'tokens_out' => 10,
        'tokens_cache_write' => 0, 'tokens_cache_read' => 0,
        'cost_usd' => ModelPricing::costFor(ModelPricing::REFERENCE_MODEL, 100, 10),
        'hypothetical_usd' => ModelPricing::costFor(ModelPricing::REFERENCE_MODEL, 100, 10),
    ]);

    $session = (new CostLedger($log))->summary()['session'];

    expect($session['cache_hits'])->toBe(0);
    expect($session['cache_saved_usd'])->toBe(0.0);
    expect($session['cache_unpriced_hits'])->toBe(0);
});
