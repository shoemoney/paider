<?php

namespace App\Providers;

readonly class ProviderResponse
{
    public function __construct(
        public string $content,
        public int $tokensIn,
        public int $tokensOut,
        public array $raw,
    ) {}
}
