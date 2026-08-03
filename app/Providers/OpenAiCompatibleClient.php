<?php

namespace App\Providers;

use App\Providers\Contracts\ProviderClient;
use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;

class OpenAiCompatibleClient implements ProviderClient
{
    private string $apiKey;

    private ClientInterface $http;

    public function __construct(
        private readonly string $baseUrl,
        private readonly string $apiKeyEnvVar,
        ?string $apiKeyOverride = null,
        ?ClientInterface $httpClient = null,
    ) {
        $this->apiKey = $apiKeyOverride ?? (getenv($this->apiKeyEnvVar) ?: '');
        $this->http = $httpClient ?? new Client;
    }

    public function send(array $messages, string $model, array $options = []): ProviderResponse
    {
        QwenPlanKeyGuard::assertSafe($this->apiKey, $this->baseUrl);

        if ($this->apiKey === '') {
            throw new \RuntimeException("{$this->apiKeyEnvVar} is not set.");
        }

        $response = $this->http->request('POST', "{$this->baseUrl}/chat/completions", [
            'headers' => [
                'Authorization' => "Bearer {$this->apiKey}",
            ],
            // $options carries provider-specific knobs (temperature, DeepSeek's
            // `thinking`, response_format). It is merged UNDER the fixed keys, never over
            // them: `model` is what the cost ledger prices and what the served-model
            // mismatch check compares against, so an option able to swap it would book
            // one model's tokens at another's rate — a false number printed confidently,
            // which is the exact defect class §15 was written against.
            'json' => array_merge($options, [
                'model' => $model,
                'messages' => $messages,
            ]),
        ]);

        // See AnthropicClient: a non-JSON body must fail loudly rather than decode to null and
        // masquerade as a successful empty completion costing nothing.
        $raw = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);

        return new ProviderResponse(
            content: $raw['choices'][0]['message']['content'] ?? '',
            tokensIn: $raw['usage']['prompt_tokens'] ?? 0,
            tokensOut: $raw['usage']['completion_tokens'] ?? 0,
            raw: $raw,
            // OpenAI-compatible/OpenRouter chat-completions responses echo the model that
            // actually served the request — OpenRouter documents this can differ from the
            // requested id under routing/fallback.
            servedModel: is_string($raw['model'] ?? null) ? $raw['model'] : null,
        );
    }
}
