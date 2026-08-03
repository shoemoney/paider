<?php

use App\Support\CostComparison;

/*
 * CostComparison is a pure function over CostLedger::summary()'s array shape — no
 * ledger, no DB, no Termwind. We test it with array literals in / array out, and only
 * touch config() to seed prices for App\Support\ModelPricing, which it calls into.
 */

function summaryFixture(): array
{
    return [
        'orchestrator' => ['calls' => 14, 'tokens_in' => 61_200, 'tokens_out' => 19_800, 'spend_usd' => 0.801],
        'coder' => ['calls' => 203, 'tokens_in' => 1_400_000, 'tokens_out' => 287_100, 'spend_usd' => 0.079],
        'research' => ['calls' => 118, 'tokens_in' => 1_800_000, 'tokens_out' => 34_600, 'spend_usd' => 0.058],
        'session' => [
            'calls' => 335,
            'tokens_in' => 61_200 + 1_400_000 + 1_800_000,
            'tokens_out' => 19_800 + 287_100 + 34_600,
            'spend_usd' => 0.801 + 0.079 + 0.058,
        ],
    ];
}

it('reprices the session against a reference model and computes token/spend shares', function () {
    config(['prices' => ['test/reference' => ['in' => 5.00, 'out' => 25.00]]]);

    $summary = summaryFixture();
    $result = CostComparison::compare($summary, 'test/reference');

    $tokensIn = $summary['session']['tokens_in'];
    $tokensOut = $summary['session']['tokens_out'];
    $expectedHypothetical = $tokensIn / 1e6 * 5.00 + $tokensOut / 1e6 * 25.00;

    $routedTokens = ($summary['coder']['tokens_in'] + $summary['coder']['tokens_out'])
        + ($summary['research']['tokens_in'] + $summary['research']['tokens_out']);
    $routedSpend = $summary['coder']['spend_usd'] + $summary['research']['spend_usd'];
    $totalTokens = $tokensIn + $tokensOut;

    expect($result['hypothetical_usd'])->toBe($expectedHypothetical)
        ->and($result['saved_usd'])->toBe($expectedHypothetical - $summary['session']['spend_usd'])
        ->and($result['token_share_pct'])->toBe($routedTokens / $totalTokens * 100)
        ->and($result['spend_share_pct'])->toBe($routedSpend / $summary['session']['spend_usd'] * 100);
});

it('returns a null saved_usd (not a fabricated figure) when every call was unpriced', function () {
    config(['prices' => ['test/reference' => ['in' => 1.00, 'out' => 2.00]]]);

    // All calls logged against unpriced models: tokens are real, spend_usd collapsed to
    // 0.0 (CostLedger sums cost_usd ?? 0.0), but unpriced_calls says that 0.0 isn't real
    // spend — it's unknown. saved_usd must not be derived against an unknown subtrahend
    // (LOCKED decision #3: unpriced is null, never a silent $0.00).
    $summary = [
        'coder' => ['calls' => 5, 'tokens_in' => 10_000, 'tokens_out' => 2_000, 'spend_usd' => 0.0],
        'session' => ['calls' => 5, 'tokens_in' => 10_000, 'tokens_out' => 2_000, 'spend_usd' => 0.0, 'unpriced_calls' => 5],
    ];

    $result = CostComparison::compare($summary, 'test/reference');

    expect($result['hypothetical_usd'])->toBe(10_000 / 1e6 * 1.00 + 2_000 / 1e6 * 2.00)
        ->and($result['saved_usd'])->toBeNull()
        ->and($result['token_share_pct'])->toBeNull()
        ->and($result['spend_share_pct'])->toBeNull();
});

it('returns null for hypothetical, saved, and both shares when the reference model is unpriced', function () {
    $summary = summaryFixture();

    // No config('prices.*') entry seeded for this id — ModelPricing::costFor() returns
    // null, and CostComparison propagates that rather than inventing a $0.00 figure.
    $result = CostComparison::compare($summary, 'nobody/knows-this-model');

    expect($result)->toBe([
        'hypothetical_usd' => null,
        'saved_usd' => null,
        'token_share_pct' => null,
        'spend_share_pct' => null,
    ]);
});

it('treats an all-orchestrator session as zero routed tokens and zero routed spend', function () {
    config(['prices' => ['test/reference' => ['in' => 5.00, 'out' => 25.00]]]);

    $summary = [
        'orchestrator' => ['calls' => 3, 'tokens_in' => 1_000, 'tokens_out' => 200, 'spend_usd' => 0.5],
        'session' => ['calls' => 3, 'tokens_in' => 1_000, 'tokens_out' => 200, 'spend_usd' => 0.5],
    ];

    $result = CostComparison::compare($summary, 'test/reference');

    expect($result['token_share_pct'])->toBe(0.0)
        ->and($result['spend_share_pct'])->toBe(0.0);
});

it('prices against the real shipped config, not just a test fixture', function () {
    // Goes through the actual config/prices.php entry so this test breaks if that
    // file's shape ever drifts from what ModelPricing/CostComparison expect.
    $summary = [
        'session' => ['calls' => 1, 'tokens_in' => 1_000_000, 'tokens_out' => 1_000_000, 'spend_usd' => 0.0],
    ];

    $result = CostComparison::compare($summary, 'anthropic/claude-opus-5');

    expect($result['hypothetical_usd'])->toBe(30.0);
});
