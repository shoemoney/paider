<?php

namespace App\Support;

/**
 * Pure function over CostLedger::summary()'s shape — no DB, no Termwind, no config
 * reads beyond ModelPricing. Answers the README's "same work on all-Opus 5" line: what
 * would this session's recorded tokens have cost on one reference model, and how much
 * of the volume moved through the cheaper, non-orchestrator tiers.
 *
 * Deviation from a literal `hypothetical_usd => float` shape: when $referenceModel is
 * unpriced, ModelPricing::costFor() returns null rather than 0.0 (see LOCKED decision
 * #3 — an unpriced model is unknown, not free). Inventing a $0.00 "same work" figure
 * would be the exact silent-zero bug this repo has already fixed six times over, so
 * hypothetical_usd (and everything derived from it) is nullable here too.
 */
class CostComparison
{
    public static function compare(array $summary, string $referenceModel): array
    {
        $session = $summary['session'];

        $hypotheticalUsd = ModelPricing::costFor(
            $referenceModel,
            $session['tokens_in'],
            $session['tokens_out']
        );

        if ($hypotheticalUsd === null) {
            return [
                'hypothetical_usd' => null,
                'saved_usd' => null,
                'token_share_pct' => null,
                'spend_share_pct' => null,
            ];
        }

        // Unpriced calls make the session's real spend unknown, not zero — deriving
        // "saved" against an unknown subtrahend would be the same silent-zero bug one
        // layer up that LOCKED decision #3 exists to prevent.
        $savedUsd = ($session['unpriced_calls'] ?? 0) > 0
            ? null
            : $hypotheticalUsd - $session['spend_usd'];

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
            'spend_share_pct' => (float) $routedSpend / $session['spend_usd'] * 100,
        ];
    }
}
