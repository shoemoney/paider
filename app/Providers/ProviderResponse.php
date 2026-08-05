<?php

namespace App\Providers;

readonly class ProviderResponse
{
    public function __construct(
        public string $content,
        public int $tokensIn,
        public int $tokensOut,
        public array $raw,
        // The model id the provider says actually served the request — Anthropic and
        // OpenAI-compatible/OpenRouter responses both carry a top-level 'model' field that
        // can differ from what was requested (alias resolution, routing/fallback). Null
        // when the response carries none (or in tests using raw: []); callers fall back to
        // the requested id in that case.
        public ?string $servedModel = null,
        // Cache tokens, normalised so both are DISJOINT from $tokensIn. The two
        // provider families disagree about this and the disagreement is silent:
        // Anthropic's input_tokens EXCLUDES cache tokens (they arrive as their own
        // fields), while an OpenAI-compatible prompt_tokens INCLUDES the cached
        // ones as a subset. Adding both without subtracting on the OpenAI side
        // bills the same tokens twice, at the more expensive rate, with nothing to
        // notice it. Each client is responsible for handing these over disjoint.
        public int $cacheWrite = 0,
        public int $cacheRead = 0,
    ) {}
}
