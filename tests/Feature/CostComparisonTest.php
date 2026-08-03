<?php

use App\Support\CostComparison;

/*
 * CostComparison is a pure function over CostLedger::summary()'s array shape — no
 * ledger, no DB, no Termwind, no config reads at all. hypothetical_usd/saved_usd
 * come straight off the summary's own (write-time-frozen) fields; this file feeds
 * array literals in and checks arrays out.
 */

function summaryFixture(): array
{
    // hypothetical_usd values below are what Loop.php/CommitCommand.php would have
    // frozen at write time against the reference model — $5/$25 per Mtok, same as
    // the shipped anthropic/claude-opus-5 entry, kept as a literal here so this file
    // has no dependency on config/prices.php.
    $hOrchestrator = 61_200 / 1e6 * 5.00 + 19_800 / 1e6 * 25.00;
    $hCoder = 1_400_000 / 1e6 * 5.00 + 287_100 / 1e6 * 25.00;
    $hResearch = 1_800_000 / 1e6 * 5.00 + 34_600 / 1e6 * 25.00;

    return [
        'orchestrator' => ['calls' => 14, 'tokens_in' => 61_200, 'tokens_out' => 19_800, 'spend_usd' => 0.801, 'hypothetical_usd' => $hOrchestrator],
        'coder' => ['calls' => 203, 'tokens_in' => 1_400_000, 'tokens_out' => 287_100, 'spend_usd' => 0.079, 'hypothetical_usd' => $hCoder],
        'research' => ['calls' => 118, 'tokens_in' => 1_800_000, 'tokens_out' => 34_600, 'spend_usd' => 0.058, 'hypothetical_usd' => $hResearch],
        'session' => [
            'calls' => 335,
            'tokens_in' => 61_200 + 1_400_000 + 1_800_000,
            'tokens_out' => 19_800 + 287_100 + 34_600,
            'spend_usd' => 0.801 + 0.079 + 0.058,
            'unpriced_calls' => 0,
            'hypothetical_usd' => $hOrchestrator + $hCoder + $hResearch,
            'hypothetical_unknown' => 0,
        ],
    ];
}

it('returns the stored hypothetical/saved figures verbatim and computes token/spend shares', function () {
    $summary = summaryFixture();
    $result = CostComparison::compare($summary);

    $tokensIn = $summary['session']['tokens_in'];
    $tokensOut = $summary['session']['tokens_out'];

    $routedTokens = ($summary['coder']['tokens_in'] + $summary['coder']['tokens_out'])
        + ($summary['research']['tokens_in'] + $summary['research']['tokens_out']);
    $routedSpend = $summary['coder']['spend_usd'] + $summary['research']['spend_usd'];
    $totalTokens = $tokensIn + $tokensOut;

    expect($result['hypothetical_usd'])->toBe($summary['session']['hypothetical_usd'])
        ->and($result['saved_usd'])->toBe($summary['session']['hypothetical_usd'] - $summary['session']['spend_usd'])
        ->and($result['token_share_pct'])->toBe($routedTokens / $totalTokens * 100)
        ->and($result['spend_share_pct'])->toBe($routedSpend / $summary['session']['spend_usd'] * 100);
});

it('returns a null saved_usd (not a fabricated figure) when every call was unpriced, but keeps the known hypothetical', function () {
    // All calls logged against unpriced models: tokens are real, spend_usd collapsed to
    // 0.0 (CostLedger sums cost_usd ?? 0.0), but unpriced_calls says that 0.0 isn't real
    // spend — it's unknown. saved_usd must not be derived against an unknown subtrahend
    // (LOCKED decision #3: unpriced is null, never a silent $0.00). hypothetical_usd is
    // unaffected -- it was frozen against a different (reference) model entirely.
    $summary = [
        'coder' => ['calls' => 5, 'tokens_in' => 10_000, 'tokens_out' => 2_000, 'spend_usd' => 0.0],
        'session' => [
            'calls' => 5, 'tokens_in' => 10_000, 'tokens_out' => 2_000, 'spend_usd' => 0.0,
            'unpriced_calls' => 5, 'hypothetical_usd' => 0.06, 'hypothetical_unknown' => 0,
        ],
    ];

    $result = CostComparison::compare($summary);

    expect($result['hypothetical_usd'])->toBe(0.06)
        ->and($result['saved_usd'])->toBeNull()
        ->and($result['token_share_pct'])->toBeNull()
        ->and($result['spend_share_pct'])->toBeNull();
});

it('nulls hypothetical_usd and saved_usd -- but NOT the token/spend shares -- when any event predates the hypothetical_usd field', function () {
    // A mixed old/new log (hypothetical_unknown > 0) must not silently compare a
    // frozen-old-basis sum against nothing -- but token_share_pct/spend_share_pct are
    // driven entirely by tokens and spend_usd, never by the reference-model
    // hypothetical, so they stay real numbers rather than being dragged down too.
    $summary = [
        'orchestrator' => ['calls' => 14, 'tokens_in' => 61_200, 'tokens_out' => 19_800, 'spend_usd' => 0.801],
        'coder' => ['calls' => 203, 'tokens_in' => 1_400_000, 'tokens_out' => 287_100, 'spend_usd' => 0.079],
        'session' => [
            'calls' => 217, 'tokens_in' => 1_461_200, 'tokens_out' => 306_900, 'spend_usd' => 0.88,
            'unpriced_calls' => 0, 'hypothetical_usd' => 0.0, 'hypothetical_unknown' => 3,
        ],
    ];

    $result = CostComparison::compare($summary);

    expect($result['hypothetical_usd'])->toBeNull()
        ->and($result['saved_usd'])->toBeNull()
        ->and($result['token_share_pct'])->not->toBeNull()
        ->and($result['spend_share_pct'])->not->toBeNull();
});

it('treats an all-orchestrator session as zero routed tokens and zero routed spend', function () {
    $summary = [
        'orchestrator' => ['calls' => 3, 'tokens_in' => 1_000, 'tokens_out' => 200, 'spend_usd' => 0.5],
        'session' => [
            'calls' => 3, 'tokens_in' => 1_000, 'tokens_out' => 200, 'spend_usd' => 0.5,
            'unpriced_calls' => 0, 'hypothetical_usd' => 0.6, 'hypothetical_unknown' => 0,
        ],
    ];

    $result = CostComparison::compare($summary);

    expect($result['token_share_pct'])->toBe(0.0)
        ->and($result['spend_share_pct'])->toBe(0.0);
});

it('treats a session with no orchestrator tier as fully routed away', function () {
    // `paider commit` logs tier => 'fast' and never 'orchestrator' (CommitCommand.php),
    // so a commit-only session's ledger summary has no 'orchestrator' key at all.
    $summary = [
        'fast' => ['calls' => 3, 'tokens_in' => 1_000, 'tokens_out' => 200, 'spend_usd' => 1.0],
        'session' => [
            'calls' => 3, 'tokens_in' => 1_000, 'tokens_out' => 200, 'spend_usd' => 1.0,
            'unpriced_calls' => 0, 'hypothetical_usd' => 1.2, 'hypothetical_unknown' => 0,
        ],
    ];

    $result = CostComparison::compare($summary);

    expect($result['token_share_pct'])->toBe(100.0)
        ->and($result['spend_share_pct'])->toBe(100.0);
});

it('nulls only spend_share_pct -- not token_share_pct -- when the session has unpriced calls alongside real spend', function () {
    // A mixed session: one tier fully unpriced (real tokens, unknown spend), one tier
    // priced. token_share_pct is a real, independently-measured fact (tokens are
    // recorded regardless of pricing); spend_share_pct would divide through a spend
    // total that's known to exclude the unpriced tier's real cost, so it alone must
    // come back null rather than a confident (and inverted) percentage.
    $summary = [
        'research' => ['calls' => 1, 'tokens_in' => 900_000, 'tokens_out' => 900_000, 'spend_usd' => 0.0],
        'orchestrator' => ['calls' => 1, 'tokens_in' => 1_000, 'tokens_out' => 200, 'spend_usd' => 5.0],
        'session' => [
            'calls' => 2, 'tokens_in' => 901_000, 'tokens_out' => 900_200, 'spend_usd' => 5.0,
            'unpriced_calls' => 1, 'hypothetical_usd' => 5.0, 'hypothetical_unknown' => 0,
        ],
    ];

    $result = CostComparison::compare($summary);

    expect($result['spend_share_pct'])->toBeNull()
        ->and($result['token_share_pct'])->not->toBeNull()
        ->and($result['token_share_pct'])->toBeFloat();
});
