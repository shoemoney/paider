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
    ) {}
}
