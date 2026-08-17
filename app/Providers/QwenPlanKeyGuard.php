<?php

namespace App\Providers;

/**
 * A Qwen Coding Plan key (sk-sp-...) billed against a non-plan endpoint bills
 * pay-as-you-go silently. Refuse loudly instead of letting that happen.
 */
class QwenPlanKeyGuard
{
    /**
     * Hosts that bill against the plan rather than pay-as-you-go.
     *
     * `token-plan.<region>.maas.aliyuncs.com` is where the vaulted sk-sp- key actually
     * serves from, and it was missing here — so the guard refused the one endpoint it
     * exists to permit, making the plan key unusable while the PAYG endpoint it was
     * written to catch stayed correctly blocked. Matched on host only: a substring test
     * against the whole URL would let `https://evil.example.com/?x=coding.dashscope`
     * through on its query string.
     */
    private const PLAN_HOST_SUFFIXES = [
        'coding.dashscope.aliyuncs.com',
        'maas.aliyuncs.com',
    ];

    /**
     * Models confirmed (ops memory qwen-plan-model-allowlist, 2026-08) NOT on the
     * sk-sp- plan's server-side model allowlist. The plan serves an exact-string
     * list we do not have in full, so this only lists known-bad models rather than
     * guessing the allowed set. Only checked for sk-sp- keys -- an OpenRouter or
     * other non-plan key using the same model id is unaffected.
     */
    private const PLAN_ALLOWLIST_BLOCKED_MODELS = [
        'qwen/qwen3.7-flash',
    ];

    public static function assertSafe(string $apiKey, string $baseUrl, ?string $model = null): void
    {
        if (! str_starts_with($apiKey, 'sk-sp-')) {
            return;
        }

        if ($model !== null && in_array($model, self::PLAN_ALLOWLIST_BLOCKED_MODELS, true)) {
            throw new \RuntimeException(
                "Qwen Coding Plan key (sk-sp-...) used with model '{$model}' -- this model is not on the plan's server-side allowlist, so the call fails at runtime (or silently falls back to pay-as-you-go). Refusing."
            );
        }

        $host = strtolower((string) parse_url($baseUrl, PHP_URL_HOST));

        foreach (self::PLAN_HOST_SUFFIXES as $suffix) {
            if ($host === $suffix || str_ends_with($host, '.'.$suffix)) {
                return;
            }
        }

        throw new \RuntimeException(
            "Qwen Coding Plan key (sk-sp-...) used against non-plan endpoint '{$baseUrl}' — this bills pay-as-you-go instead of the plan. Refusing."
        );
    }
}
