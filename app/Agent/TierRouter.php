<?php

namespace App\Agent;

use App\Support\SettingsStore;

/**
 * Maps an operation to a tier and resolves that tier to a model under the
 * active preset. The operation->tier mapping is fixed in v0.1 — deliberately
 * not configurable, see PLAN.md.
 */
class TierRouter
{
    private const OPERATION_TIERS = [
        'plan' => 'orchestrator',
        'edit' => 'coder',
        'search' => 'research',
        'summarize' => 'research',
        'commit-msg' => 'fast',
    ];

    /**
     * @param  array<string, string>  $sessionOverrides  tier => model, never persisted
     * @return array{provider: string, tier: string, model: string}
     */
    public function resolve(string $operation, array $sessionOverrides = []): array
    {
        if (! array_key_exists($operation, self::OPERATION_TIERS)) {
            throw new \InvalidArgumentException("Unknown operation: {$operation}");
        }

        $tier = self::OPERATION_TIERS[$operation];
        $preset = SettingsStore::activePreset();

        $model = $sessionOverrides[$tier] ?? config("presets.{$preset}.{$tier}");

        return [
            'provider' => $preset,
            'tier' => $tier,
            'model' => $model,
        ];
    }
}
