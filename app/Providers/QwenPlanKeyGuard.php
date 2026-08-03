<?php

namespace App\Providers;

/**
 * A Qwen Coding Plan key (sk-sp-...) billed against a non-plan endpoint bills
 * pay-as-you-go silently. Refuse loudly instead of letting that happen.
 */
class QwenPlanKeyGuard
{
    public static function assertSafe(string $apiKey, string $baseUrl): void
    {
        if (str_starts_with($apiKey, 'sk-sp-') && ! str_contains($baseUrl, 'coding.dashscope')) {
            throw new \RuntimeException(
                "Qwen Coding Plan key (sk-sp-...) used against non-plan endpoint '{$baseUrl}' — this bills pay-as-you-go instead of the plan. Refusing."
            );
        }
    }
}
