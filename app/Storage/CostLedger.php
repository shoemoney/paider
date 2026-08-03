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

        foreach ($this->events->all() as $event) {
            if ($event['type'] !== 'tier_call') {
                continue;
            }

            $payload = $event['payload'];
            $tier = $payload['tier'];

            $tiers[$tier] ??= ['calls' => 0, 'tokens_in' => 0, 'tokens_out' => 0, 'spend_usd' => 0.0];

            $tiers[$tier]['calls']++;
            $tiers[$tier]['tokens_in'] += $payload['tokens_in'];
            $tiers[$tier]['tokens_out'] += $payload['tokens_out'];
            $tiers[$tier]['spend_usd'] += $payload['cost_usd'] ?? 0.0;
        }

        $session = ['calls' => 0, 'tokens_in' => 0, 'tokens_out' => 0, 'spend_usd' => 0.0];

        foreach ($tiers as $tier) {
            $session['calls'] += $tier['calls'];
            $session['tokens_in'] += $tier['tokens_in'];
            $session['tokens_out'] += $tier['tokens_out'];
            $session['spend_usd'] += $tier['spend_usd'];
        }

        $tiers['session'] = $session;

        return $tiers;
    }
}
