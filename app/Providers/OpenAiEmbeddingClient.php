<?php

namespace App\Providers;

use App\Providers\Contracts\EmbeddingClient;
use App\Storage\ProjectEnv;
use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use RuntimeException;

/**
 * OpenAI-compatible embeddings, against any host that speaks the shape.
 *
 * WHY THIS EXISTS RATHER THAN AN OPENROUTER ONE. OpenRouter was the first choice — it is already
 * this project's provider layer, so its calls are already priced and booked. It does not work:
 * its live model catalog returns 460 models and every one of them is a text->text chat model,
 * with no embedding model among them. Its /embeddings path exists but has no models behind it.
 * Sending embedding traffic there would fail at the first real call, in production, for a user
 * who had configured everything correctly. Measured against the live API, not assumed.
 *
 * So the default is the OpenAI-compatible shape, which OpenAI, Voyage, and most local servers
 * (Ollama, LM Studio, llama.cpp) all speak. A self-hosted embedder costs nothing per call and
 * sends nothing off the machine, which suits a tool whose whole premise is a local SQLite file.
 * Point it elsewhere with PAIDER_EMBEDDING_URL.
 *
 * The class is named for the SHAPE, not the vendor, for exactly that reason.
 *
 * ON COST. Every embed() appends an `embedding_call` event (see RagStore) which CostLedger prices
 * through the same ModelPricing::costFor() as a chat turn, including its all-zero-means-unknown
 * rule. So an unpriced or unreported embedding shows as UNPRICED, never as a confident $0.00.
 */
final class OpenAiEmbeddingClient implements EmbeddingClient
{
    private int $lastTokenCount = 0;

    private readonly string $apiKey;

    public function __construct(
        private readonly ClientInterface $http,
        ?string $apiKey = null,
        private readonly string $model = 'text-embedding-3-small',
        private readonly string $baseUrl = 'https://api.openai.com/v1',
    ) {
        // Read from the real environment, never a project .env: this is a provider credential,
        // and ProjectEnv exists precisely so a cloned repository cannot configure one for you.
        $key = $apiKey ?? (getenv('OPENAI_API_KEY') ?: getenv('PAIDER_EMBEDDING_API_KEY'));

        // An empty key is allowed, and deliberately: a LOCAL server (Ollama, LM Studio) needs
        // none. Refusing here would make a free, offline embedder impossible to configure, which
        // is the configuration most worth supporting.
        $this->apiKey = is_string($key) ? $key : '';
    }

    public function model(): string
    {
        return $this->model;
    }

    public function lastTokenCount(): int
    {
        return $this->lastTokenCount;
    }

    public function embed(array $inputs): array
    {
        $this->lastTokenCount = 0;

        if ($inputs === []) {
            return [];
        }

        foreach ($inputs as $input) {
            if (! is_string($input)) {
                throw new RuntimeException('Embedding inputs must be strings');
            }
        }

        $headers = ['Content-Type' => 'application/json'];

        if ($this->apiKey !== '') {
            $headers['Authorization'] = "Bearer {$this->apiKey}";
        }

        $response = $this->http->request('POST', "{$this->baseUrl}/embeddings", [
            'headers' => $headers,
            'json' => ['model' => $this->model, 'input' => array_values($inputs)],
            'timeout' => 60,
        ]);

        $body = json_decode((string) $response->getBody(), true);

        if (! is_array($body) || ! is_array($body['data'] ?? null)) {
            throw new RuntimeException('Embedding response was not a usable payload');
        }

        $byIndex = [];

        foreach ($body['data'] as $item) {
            if (! is_array($item) || ! is_int($item['index'] ?? null) || ! is_array($item['embedding'] ?? null)) {
                throw new RuntimeException('Embedding response had a malformed entry');
            }
            $byIndex[$item['index']] = array_map('floatval', $item['embedding']);
        }

        // Reassembled BY INDEX, not by array position. A provider that returns a batch out of
        // order is a real failure mode, and trusting position would attach a vector to the wrong
        // chunk — a wrong RAG answer that looks entirely healthy and is unfalsifiable by eye.
        $vectors = [];
        foreach (array_keys($inputs) as $position) {
            if (! isset($byIndex[$position])) {
                throw new RuntimeException("Embedding response omitted input #{$position}");
            }
            $vectors[] = $byIndex[$position];
        }

        $this->lastTokenCount = (int) ($body['usage']['total_tokens'] ?? 0);

        return $vectors;
    }

    public static function fromEnvironment(?ClientInterface $http = null): self
    {
        $url = ProjectEnv::get('PAIDER_EMBEDDING_URL', 'https://api.openai.com/v1');
        $model = ProjectEnv::get('PAIDER_EMBEDDING_MODEL', 'text-embedding-3-small');

        return new self(
            http: $http ?? new Client,
            model: is_string($model) && $model !== '' ? $model : 'text-embedding-3-small',
            baseUrl: is_string($url) && $url !== '' ? rtrim($url, '/') : 'https://api.openai.com/v1',
        );
    }
}
