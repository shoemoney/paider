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
            'json' => [
                'model' => $model,
                'messages' => $messages,
            ],
        ]);

        $raw = json_decode((string) $response->getBody(), true);

        return new ProviderResponse(
            content: $raw['choices'][0]['message']['content'] ?? '',
            tokensIn: $raw['usage']['prompt_tokens'] ?? 0,
            tokensOut: $raw['usage']['completion_tokens'] ?? 0,
            raw: $raw,
        );
    }
}
