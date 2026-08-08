<?php

namespace App\Providers;

use App\Providers\Contracts\ProviderClient;

/**
 * Single preset -> ProviderClient resolver shared by ChatCommand and CommitCommand,
 * so the same preset resolves to the same endpoint and key in both. Previously each
 * command wired this independently: ChatCommand only ever chose anthropic vs
 * OpenRouter (never touching the direct kimi/deepseek/qwen/xai/glm endpoints
 * CommitCommand used), while CommitCommand used a direct endpoint unconditionally
 * with no OpenRouter fallback when its key was missing. That divergence meant
 * switching between /chat and /commit could silently change which endpoint -- and
 * which bill -- a preset actually hit. See PLAN.md's Provider layer table.
 */
class ProviderResolver
{
    /** @var array<string, array{0: string, 1: string}> preset => [base URL, key env var] */
    private const DIRECT = [
        'kimi' => ['https://api.moonshot.ai/v1', 'MOONSHOT_API_KEY'],
        'deepseek' => ['https://api.deepseek.com', 'DEEPSEEK_API_KEY'],
        'xai' => ['https://api.x.ai/v1', 'XAI_API_KEY'],
        'glm' => ['https://open.bigmodel.cn/api/paas/v4', 'GLM_API_KEY'],
        'meta' => ['https://api.meta.ai/v1', 'META_API_KEY'],
    ];

    /** aigate provider id per preset (for Aigate::fetchKey) */
    private const AIGATE_PROVIDER = [
        'meta' => 'meta',
        'kimi' => 'kimi',
        'deepseek' => 'deepseek',
        'xai' => 'xai',
        'glm' => 'glm',
        'qwen' => 'qwencloud',
    ];

    private const OPENROUTER_URL = 'https://openrouter.ai/api/v1';

    public static function forPreset(string $preset): ProviderClient
    {
        if ($preset === 'anthropic') {
            return new AnthropicClient;
        }

        if ($preset === 'qwen') {
            return self::forQwen();
        }

        // Only take the direct endpoint when its own key is actually set -- a
        // preset like 'kimi' with no MOONSHOT_API_KEY falls through to OpenRouter
        // rather than hard-failing a session that has a perfectly good aggregator key.
        // Also try aigate if the direct env var is empty but AIGATE_URL/AIGATE_TOKEN are set.
        if (isset(self::DIRECT[$preset])) {
            [$baseUrl, $envVar] = self::DIRECT[$preset];
            $aigateProvider = self::AIGATE_PROVIDER[$preset] ?? $preset;

            // Prefer direct env, else try aigate
            $hasKey = (string) getenv($envVar) !== '';
            if (! $hasKey) {
                $hasKey = Aigate::resolveKey($envVar, $aigateProvider) !== null;
            }

            if ($hasKey) {
                return new OpenAiCompatibleClient($baseUrl, $envVar);
            }
        }

        // openai, google, open, open-frugal, balanced have no documented direct
        // endpoint (PLAN.md's Provider layer table) -- and a direct-endpoint preset
        // whose own key isn't set lands here too, same fallback either way.
        return new OpenAiCompatibleClient(self::OPENROUTER_URL, 'OPENROUTER_API_KEY');
    }

    /**
     * qwen gets its own path because a Coding Plan key with no plan URL configured
     * throws (see qwenBaseUrl()) rather than silently billing pay-as-you-go -- and
     * that throw needs to become an OpenRouter fallback here when one is available,
     * with the plan-key guidance surfaced on STDERR rather than swallowed, since
     * which endpoint bills is exactly the thing an operator should get to see.
     */
    private static function forQwen(): ProviderClient
    {
        if ((string) getenv('DASHSCOPE_API_KEY') === '') {
            return new OpenAiCompatibleClient(self::OPENROUTER_URL, 'OPENROUTER_API_KEY');
        }

        try {
            $baseUrl = self::qwenBaseUrl();
        } catch (\RuntimeException $e) {
            if ((string) getenv('OPENROUTER_API_KEY') === '') {
                throw $e;
            }

            fwrite(STDERR, "warning: {$e->getMessage()}\nfalling back to OpenRouter for this request.\n");

            return new OpenAiCompatibleClient(self::OPENROUTER_URL, 'OPENROUTER_API_KEY');
        }

        return new OpenAiCompatibleClient($baseUrl, 'DASHSCOPE_API_KEY');
    }

    /**
     * A Coding Plan key (sk-sp-) only bills against the plan on the account's own
     * "Plan Exclusive Base URL", which is region-scoped
     * (token-plan.<region>.maas.aliyuncs.com) and therefore cannot be hardcoded
     * here for everyone.
     *
     * Without this, a plan key fell through to the pay-as-you-go default and
     * QwenPlanKeyGuard refused it -- correct, but the operator is left holding a
     * refusal with nothing telling them which URL would have worked. Name the
     * setting and where to copy it from instead.
     *
     * getenv(), not env() -- matches the read AnthropicClient/OpenAiCompatibleClient
     * already use for their own key reads, and putenv() feeds both readers (Laravel's
     * env() adapter is a putenv/getenv wrapper), so this is a convention match, not a
     * behavior change.
     */
    public static function qwenBaseUrl(): string
    {
        $payg = 'https://dashscope.aliyuncs.com/compatible-mode/v1';

        if (! str_starts_with((string) getenv('DASHSCOPE_API_KEY'), 'sk-sp-')) {
            return $payg;
        }

        $plan = trim((string) getenv('DASHSCOPE_PLAN_BASE_URL'));

        if ($plan === '') {
            throw new \RuntimeException(
                'DASHSCOPE_API_KEY is a Coding Plan key (sk-sp-...) but DASHSCOPE_PLAN_BASE_URL is unset, '
                ."so the only endpoint available is pay-as-you-go ({$payg}) — which would bill per token "
                .'instead of against the plan you already paid for. Copy "OpenAI API-Compatible Tools" from '
                .'the "Plan Exclusive Base URL" panel of your plan console and set DASHSCOPE_PLAN_BASE_URL, '
                .'e.g. https://token-plan.<your-region>.maas.aliyuncs.com/compatible-mode/v1'
            );
        }

        return $plan;
    }
}
