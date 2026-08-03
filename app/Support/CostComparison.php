<?php

namespace App\Support;

/**
 * Pure function over CostLedger::summary()'s shape — no DB, no Termwind, no config
 * reads at all. Answers the README's "same work on all-Opus 5" line: what would this
 * session's recorded tokens have cost on one reference model, and how much of the
 * volume moved through the cheaper, non-orchestrator tiers.
 *
 * hypothetical_usd is NOT recomputed here — it's the sum of each tier_call event's
 * own hypothetical_usd field, frozen at write time by Loop.php/CommitCommand.php
 * against ModelPricing::REFERENCE_MODEL (LOCKED decision #2, one field over: the
 * two sides of "you saved" must share a single price basis, so neither can be
 * silently re-priced from whatever config/prices.php says today). A log written
 * before that field existed has no key at all; CostLedger folds that into
 * hypothetical_unknown, and hypothetical_usd/saved_usd come back null rather than a
 * mixed old/new-basis number — token_share_pct/spend_share_pct are unaffected by
 * that gate since they're driven entirely by tokens/spend, not the hypothetical.
 */
class CostComparison
{
    public static function compare(array $summary): array
    {
        $session = $summary['session'];

        $hypotheticalKnown = ($session['hypothetical_unknown'] ?? 0) === 0;
        $hypotheticalUsd = $hypotheticalKnown ? ($session['hypothetical_usd'] ?? 0.0) : null;

        // Unpriced calls make the session's real spend unknown, not zero — deriving
        // "saved" against an unknown subtrahend would be the same silent-zero bug one
        // layer up that LOCKED decision #3 exists to prevent.
        $savedUsd = ($hypotheticalUsd !== null && ($session['unpriced_calls'] ?? 0) === 0)
            ? $hypotheticalUsd - $session['spend_usd']
            : null;

        // Dividing spend or tokens through a $0 session invents a percentage out of
        // nothing (0/0, or a share of a total that never happened) — null, not 0 or 100.
        if ($session['spend_usd'] === 0.0) {
            return [
                'hypothetical_usd' => $hypotheticalUsd,
                'saved_usd' => $savedUsd,
                'token_share_pct' => null,
                'spend_share_pct' => null,
            ];
        }

        $orchestrator = $summary['orchestrator'] ?? ['tokens_in' => 0, 'tokens_out' => 0, 'spend_usd' => 0.0];
        $totalTokens = $session['tokens_in'] + $session['tokens_out'];
        $routedTokens = $totalTokens - ($orchestrator['tokens_in'] + $orchestrator['tokens_out']);
        $routedSpend = $session['spend_usd'] - $orchestrator['spend_usd'];

        return [
            'hypothetical_usd' => $hypotheticalUsd,
            'saved_usd' => $savedUsd,
            'token_share_pct' => $totalTokens > 0 ? (float) $routedTokens / $totalTokens * 100 : null,
            // spend_usd (the denominator) already excludes unpriced calls by construction,
            // so with any unpriced call in the session this ratio is a share of a total
            // that's known to be wrong, not just an estimate — null, same reasoning as
            // saved_usd above, not a confident (and possibly inverted) number.
            'spend_share_pct' => ($session['unpriced_calls'] ?? 0) > 0
                ? null
                : (float) $routedSpend / $session['spend_usd'] * 100,
        ];
    }
}
