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
