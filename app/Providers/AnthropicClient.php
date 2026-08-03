<?php

namespace App\Providers;

use App\Providers\Contracts\ProviderClient;
use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;

class AnthropicClient implements ProviderClient
{
    private string $apiKey;

    private ClientInterface $http;

    public function __construct(
        ?string $apiKey = null,
        private readonly string $baseUrl = 'https://api.anthropic.com',
        ?ClientInterface $httpClient = null,
    ) {
        $this->apiKey = $apiKey ?? (getenv('ANTHROPIC_API_KEY') ?: '');
        $this->http = $httpClient ?? new Client;
    }

    public function send(array $messages, string $model, array $options = []): ProviderResponse
    {
        QwenPlanKeyGuard::assertSafe($this->apiKey, $this->baseUrl);

        if ($this->apiKey === '') {
            throw new \RuntimeException('ANTHROPIC_API_KEY is not set.');
        }

        $system = implode("\n\n", array_map(
            fn (array $m) => $m['content'],
            array_filter($messages, fn (array $m) => $m['role'] === 'system'),
        ));

        $body = [
            'model' => $model,
            'max_tokens' => 4096,
            'messages' => array_values(array_filter($messages, fn (array $m) => $m['role'] !== 'system')),
        ];

        if ($system !== '') {
            $body['system'] = $system;
        }

        $response = $this->http->request('POST', "{$this->baseUrl}/v1/messages", [
            'headers' => [
                'x-api-key' => $this->apiKey,
                'anthropic-version' => '2023-06-01',
                'content-type' => 'application/json',
            ],
            'json' => $body,
        ]);

        $raw = json_decode((string) $response->getBody(), true);

        $content = implode('', array_map(
            fn (array $block) => $block['text'] ?? '',
            array_filter($raw['content'] ?? [], fn (array $b) => ($b['type'] ?? null) === 'text'),
        ));

        return new ProviderResponse(
            content: $content,
            tokensIn: $raw['usage']['input_tokens'] ?? 0,
            tokensOut: $raw['usage']['output_tokens'] ?? 0,
            raw: $raw,
        );
    }
}
