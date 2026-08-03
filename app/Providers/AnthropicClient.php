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

        // Merged UNDER the fixed keys — see OpenAiCompatibleClient for why `model` must not
        // be overridable. `max_tokens` is the exception and reads through, since 4096 is a
        // default rather than an invariant. `system` is asserted last: it is derived from
        // the caller's own messages, and letting an option displace it would silently drop
        // the instructions the loop believes it sent.
        $body = array_merge($options, [
            'model' => $model,
            'max_tokens' => $options['max_tokens'] ?? 4096,
            'messages' => array_values(array_filter($messages, fn (array $m) => $m['role'] !== 'system')),
        ]);

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

        // A gateway or proxy error returns HTML, not JSON. Decoding that leniently yields null,
        // which flows on as empty content and zero tokens — a failed call that looks like a
        // successful empty one, and quietly books $0.00 into the cost ledger.
        $raw = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);

        $content = implode('', array_map(
            fn (array $block) => $block['text'] ?? '',
            array_filter($raw['content'] ?? [], fn (array $b) => ($b['type'] ?? null) === 'text'),
        ));

        return new ProviderResponse(
            content: $content,
            tokensIn: $raw['usage']['input_tokens'] ?? 0,
            tokensOut: $raw['usage']['output_tokens'] ?? 0,
            raw: $raw,
            // Anthropic's Messages API echoes the model that actually served the request —
            // an undated alias in the request can resolve to a dated snapshot here.
            servedModel: is_string($raw['model'] ?? null) ? $raw['model'] : null,
        );
    }
}
