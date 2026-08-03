<?php

namespace App\Storage;

/**
 * Pure read-side projection over EventLog. Holds no state of its own and is never
 * mutated directly — the cost ledger is a projection over events, never a mutable
 * balance (see LOCKED in the project brief).
 */
class CostLedger
{
    public function __construct(private readonly EventLog $events) {}

    public function summary(): array
    {
        $tiers = [];

        foreach ($this->events->stream() as $event) {
            if ($event['type'] !== 'tier_call') {
                continue;
            }

            $payload = $event['payload'];
            $tier = $payload['tier'];

            $tiers[$tier] ??= [
                'calls' => 0, 'tokens_in' => 0, 'tokens_out' => 0, 'spend_usd' => 0.0,
                'unpriced_calls' => 0, 'unpriced_models' => [],
                'hypothetical_usd' => 0.0, 'hypothetical_unknown' => 0,
            ];

            $tiers[$tier]['calls']++;
            $tiers[$tier]['tokens_in'] += $payload['tokens_in'];
            $tiers[$tier]['tokens_out'] += $payload['tokens_out'];

            // NULL cost_usd (LOCKED decision #3) marks an unpriced model, never $0.00 —
            // fold it only into unpriced_calls, not spend_usd, so a mixed session can't
            // present a confident total that silently drops what it couldn't price.
            if ($payload['cost_usd'] === null) {
                $tiers[$tier]['unpriced_calls']++;
                $tiers[$tier]['unpriced_models'][$payload['model']] = true;
            } else {
                $tiers[$tier]['spend_usd'] += $payload['cost_usd'];
            }

            // Same nullability rule as cost_usd, one field over: a legacy row written
            // before this field existed has no key at all, and `?? null` must count
            // that as unknown too, never as a silent 0.0 (CostComparison::compare()
            // relies on this to refuse a mixed old/new-basis comparison).
            $hypothetical = $payload['hypothetical_usd'] ?? null;

            if ($hypothetical === null) {
                $tiers[$tier]['hypothetical_unknown']++;
            } else {
                $tiers[$tier]['hypothetical_usd'] += $hypothetical;
            }
        }

        $session = [
            'calls' => 0, 'tokens_in' => 0, 'tokens_out' => 0, 'spend_usd' => 0.0,
            'unpriced_calls' => 0, 'unpriced_models' => [],
            'hypothetical_usd' => 0.0, 'hypothetical_unknown' => 0,
        ];

        foreach ($tiers as $tier) {
            $session['calls'] += $tier['calls'];
            $session['tokens_in'] += $tier['tokens_in'];
            $session['tokens_out'] += $tier['tokens_out'];
            $session['spend_usd'] += $tier['spend_usd'];
            $session['unpriced_calls'] += $tier['unpriced_calls'];
            $session['unpriced_models'] += $tier['unpriced_models'];
            $session['hypothetical_usd'] += $tier['hypothetical_usd'];
            $session['hypothetical_unknown'] += $tier['hypothetical_unknown'];
        }

        foreach ($tiers as &$tier) {
            $tier['unpriced_models'] = array_keys($tier['unpriced_models']);
        }
        unset($tier);
        $session['unpriced_models'] = array_keys($session['unpriced_models']);

        $tiers['session'] = $session;

        return $tiers;
    }
}
