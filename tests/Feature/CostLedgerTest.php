<?php

use App\Storage\CostLedger;
use App\Storage\Database;
use App\Storage\EventLog;

it('projects per-tier calls/tokens/spend and a session total from tier_call events', function () {
    $log = new EventLog(Database::connect(':memory:'));

    $seed = [
        ['tier' => 'orchestrator', 'provider' => 'anthropic', 'model' => 'opus', 'tokens_in' => 1000, 'tokens_out' => 200, 'cost_usd' => 0.45],
        ['tier' => 'orchestrator', 'provider' => 'anthropic', 'model' => 'opus', 'tokens_in' => 500, 'tokens_out' => 100, 'cost_usd' => 0.20],
        ['tier' => 'coder', 'provider' => 'qwen', 'model' => 'flash', 'tokens_in' => 20000, 'tokens_out' => 5000, 'cost_usd' => 0.03],
        ['tier' => 'research', 'provider' => 'qwen', 'model' => 'flash', 'tokens_in' => 40000, 'tokens_out' => 1000, 'cost_usd' => null],
        ['tier' => 'fast', 'provider' => 'qwen', 'model' => 'flash', 'tokens_in' => 300, 'tokens_out' => 50, 'cost_usd' => 0.001],
    ];

    foreach ($seed as $payload) {
        $log->append('tier_call', $payload);
    }

    // Noise: a non-tier_call event must not affect the projection.
    $log->append('note', ['text' => 'ignore me']);

    $summary = (new CostLedger($log))->summary();

    // Independently recompute expectations from the seed data, not copy-pasted numbers.
    $expected = [];
    foreach ($seed as $row) {
        $t = $row['tier'];
        $expected[$t] ??= ['calls' => 0, 'tokens_in' => 0, 'tokens_out' => 0, 'spend_usd' => 0.0];
        $expected[$t]['calls']++;
        $expected[$t]['tokens_in'] += $row['tokens_in'];
        $expected[$t]['tokens_out'] += $row['tokens_out'];
        $expected[$t]['spend_usd'] += $row['cost_usd'] ?? 0.0;
    }

    foreach (['orchestrator', 'coder', 'research', 'fast'] as $tier) {
        expect($summary[$tier]['calls'])->toBe($expected[$tier]['calls'])
            ->and($summary[$tier]['tokens_in'])->toBe($expected[$tier]['tokens_in'])
            ->and($summary[$tier]['tokens_out'])->toBe($expected[$tier]['tokens_out'])
            ->and($summary[$tier]['spend_usd'])->toEqualWithDelta($expected[$tier]['spend_usd'], 1e-9);
    }

    $sessionCalls = array_sum(array_column($expected, 'calls'));
    $sessionTokensIn = array_sum(array_column($expected, 'tokens_in'));
    $sessionTokensOut = array_sum(array_column($expected, 'tokens_out'));
    $sessionSpend = array_sum(array_column($expected, 'spend_usd'));

    expect($summary['session']['calls'])->toBe($sessionCalls)
        ->and($summary['session']['tokens_in'])->toBe($sessionTokensIn)
        ->and($summary['session']['tokens_out'])->toBe($sessionTokensOut)
        ->and($summary['session']['spend_usd'])->toEqualWithDelta($sessionSpend, 1e-9);

    // research's null cost_usd is treated as 0.0, not dropped or errored.
    expect($summary['research']['spend_usd'])->toBe(0.0)
        ->and($summary['research']['calls'])->toBe(1);
});

it('is a pure projection: two summary() calls after no new events match exactly', function () {
    $log = new EventLog(Database::connect(':memory:'));
    $log->append('tier_call', ['tier' => 'fast', 'tokens_in' => 10, 'tokens_out' => 2, 'cost_usd' => 0.001]);

    $ledger = new CostLedger($log);

    expect($ledger->summary())->toBe($ledger->summary());
});

it('counts unpriced_calls per tier and at the session level, naming the model', function () {
    $log = new EventLog(Database::connect(':memory:'));

    $log->append('tier_call', ['tier' => 'coder', 'model' => 'nobody/knows-this', 'tokens_in' => 100, 'tokens_out' => 10, 'cost_usd' => null]);
    $log->append('tier_call', ['tier' => 'coder', 'model' => 'qwen/qwen3.7-flash', 'tokens_in' => 100, 'tokens_out' => 10, 'cost_usd' => 0.01]);
    $log->append('tier_call', ['tier' => 'research', 'model' => 'nobody/knows-this', 'tokens_in' => 100, 'tokens_out' => 10, 'cost_usd' => null]);

    $summary = (new CostLedger($log))->summary();

    expect($summary['coder']['unpriced_calls'])->toBe(1)
        ->and($summary['coder']['unpriced_models'])->toBe(['nobody/knows-this'])
        ->and($summary['research']['unpriced_calls'])->toBe(1)
        ->and($summary['session']['unpriced_calls'])->toBe(2)
        ->and($summary['session']['unpriced_models'])->toBe(['nobody/knows-this']);
});

it('sums hypothetical_usd per tier/session and counts events missing the field as hypothetical_unknown', function () {
    $log = new EventLog(Database::connect(':memory:'));

    $log->append('tier_call', ['tier' => 'coder', 'tokens_in' => 100, 'tokens_out' => 10, 'cost_usd' => 0.01, 'hypothetical_usd' => 0.05]);
    $log->append('tier_call', ['tier' => 'coder', 'tokens_in' => 100, 'tokens_out' => 10, 'cost_usd' => 0.02, 'hypothetical_usd' => 0.06]);
    // A legacy row written before hypothetical_usd existed -- no key at all, must
    // count as unknown, never as a silent 0.0 (same nullability rule as cost_usd).
    $log->append('tier_call', ['tier' => 'coder', 'tokens_in' => 100, 'tokens_out' => 10, 'cost_usd' => 0.03]);

    $summary = (new CostLedger($log))->summary();

    expect($summary['coder']['hypothetical_usd'])->toEqualWithDelta(0.11, 1e-9)
        ->and($summary['coder']['hypothetical_unknown'])->toBe(1)
        ->and($summary['session']['hypothetical_usd'])->toEqualWithDelta(0.11, 1e-9)
        ->and($summary['session']['hypothetical_unknown'])->toBe(1);
});

it('counts mismatched_calls only when requested_model is present and differs from the served model', function () {
    $log = new EventLog(Database::connect(':memory:'));

    // A served-vs-requested mismatch -- must be counted.
    $log->append('tier_call', [
        'tier' => 'orchestrator', 'model' => 'anthropic/claude-sonnet-5', 'requested_model' => 'anthropic/claude-opus-5',
        'tokens_in' => 100, 'tokens_out' => 10, 'cost_usd' => 0.01,
    ]);
    // A legacy row with no 'requested_model' key at all -- must contribute nothing,
    // same "absence is unknown, not false" discipline as unpriced/hypothetical fields.
    $log->append('tier_call', [
        'tier' => 'orchestrator', 'model' => 'anthropic/claude-opus-5',
        'tokens_in' => 100, 'tokens_out' => 10, 'cost_usd' => 0.02,
    ]);
    // requested_model present but equal to model -- not a mismatch.
    $log->append('tier_call', [
        'tier' => 'orchestrator', 'model' => 'anthropic/claude-opus-5', 'requested_model' => 'anthropic/claude-opus-5',
        'tokens_in' => 100, 'tokens_out' => 10, 'cost_usd' => 0.03,
    ]);

    $summary = (new CostLedger($log))->summary();

    expect($summary['orchestrator']['mismatched_calls'])->toBe(1)
        ->and($summary['orchestrator']['mismatched_models'])->toBe(['anthropic/claude-opus-5 -> anthropic/claude-sonnet-5'])
        ->and($summary['session']['mismatched_calls'])->toBe(1)
        ->and($summary['session']['mismatched_models'])->toBe(['anthropic/claude-opus-5 -> anthropic/claude-sonnet-5']);
});

it('never re-prices: two calls to the same model with different stored cost_usd sum as stored', function () {
    // Simulates a provider price change between two calls to the same model. summary()
    // must sum whatever was written at call time, never re-derive from current prices.
    $log = new EventLog(Database::connect(':memory:'));

    $log->append('tier_call', ['tier' => 'fast', 'model' => 'qwen/qwen3.7-flash', 'tokens_in' => 1000, 'tokens_out' => 100, 'cost_usd' => 0.10]);
    $log->append('tier_call', ['tier' => 'fast', 'model' => 'qwen/qwen3.7-flash', 'tokens_in' => 1000, 'tokens_out' => 100, 'cost_usd' => 0.25]);

    $summary = (new CostLedger($log))->summary();

    expect($summary['fast']['spend_usd'])->toEqualWithDelta(0.35, 1e-9)
        ->and($summary['fast']['unpriced_calls'])->toBe(0)
        ->and($summary['session']['spend_usd'])->toEqualWithDelta(0.35, 1e-9);
});

it('sums cache tokens per tier and session, and treats a pre-697167b row as zero not unknown', function () {
    $log = new EventLog(Database::connect(':memory:'));

    // A warm Anthropic session: cache_read dwarfs tokens_in by orders of magnitude. That
    // shape is the whole reason these columns exist — a table carrying only in/out shows a
    // few hundred tokens beside the spend those millions of cached tokens actually drove.
    $seed = [
        ['tier' => 'orchestrator', 'model' => 'opus', 'tokens_in' => 12, 'tokens_out' => 400,
            'tokens_cache_write' => 8_000, 'tokens_cache_read' => 1_200_000, 'cost_usd' => 0.42],
        ['tier' => 'orchestrator', 'model' => 'opus', 'tokens_in' => 3, 'tokens_out' => 150,
            'tokens_cache_write' => 0, 'tokens_cache_read' => 980_000, 'cost_usd' => 0.30],
        ['tier' => 'coder', 'model' => 'flash', 'tokens_in' => 5_000, 'tokens_out' => 900,
            'tokens_cache_write' => 2_500, 'tokens_cache_read' => 40_000, 'cost_usd' => 0.02],
        // Written before 697167b existed: no cache keys at all. Absent means zero tokens
        // here — unlike cost_usd, where absent means unknown and must never become 0.0.
        ['tier' => 'coder', 'model' => 'flash', 'tokens_in' => 700, 'tokens_out' => 100, 'cost_usd' => 0.004],
    ];

    foreach ($seed as $payload) {
        $log->append('tier_call', $payload);
    }

    $summary = (new CostLedger($log))->summary();

    // Recomputed from the seed, not copy-pasted totals.
    $expect = [];
    foreach ($seed as $row) {
        $t = $row['tier'];
        $expect[$t] ??= ['w' => 0, 'r' => 0];
        $expect[$t]['w'] += $row['tokens_cache_write'] ?? 0;
        $expect[$t]['r'] += $row['tokens_cache_read'] ?? 0;
    }

    foreach (['orchestrator', 'coder'] as $tier) {
        expect($summary[$tier]['tokens_cache_write'])->toBe($expect[$tier]['w'])
            ->and($summary[$tier]['tokens_cache_read'])->toBe($expect[$tier]['r']);
    }

    expect($summary['session']['tokens_cache_write'])->toBe($expect['orchestrator']['w'] + $expect['coder']['w'])
        ->and($summary['session']['tokens_cache_read'])->toBe($expect['orchestrator']['r'] + $expect['coder']['r']);

    // The point of the columns, stated as an assertion: cached volume can exceed plain
    // input by orders of magnitude, so a table omitting it cannot explain its own spend.
    expect($summary['session']['tokens_cache_read'])->toBeGreaterThan($summary['session']['tokens_in'] * 100);
});
