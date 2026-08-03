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

    public static function assertSafe(string $apiKey, string $baseUrl): void
    {
        if (! str_starts_with($apiKey, 'sk-sp-')) {
            return;
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
