<?php

namespace App\Providers;

use GuzzleHttp\Client;

/**
 * Thin client for aigate — Jeremy's AES-256-GCM key registry.
 * GET $AIGATE_URL/api/keys/<provider> with Bearer $AIGATE_TOKEN returns {"key": "..."}.
 * Optional: if env not set, caller falls back to direct env var.
 */
class Aigate
{
    public static function fetchKey(string $provider): ?string
    {
        $url = trim((string) getenv('AIGATE_URL'));
        $token = trim((string) getenv('AIGATE_TOKEN'));

        if ($url === '' || $token === '') {
            return null;
        }

        $endpoint = rtrim($url, '/').'/api/keys/'.rawurlencode($provider);

        try {
            $client = new Client(['timeout' => 5, 'http_errors' => false]);
            $res = $client->get($endpoint, [
                'headers' => ['Authorization' => 'Bearer '.$token, 'Accept' => 'application/json'],
            ]);

            if ($res->getStatusCode() !== 200) {
                return null;
            }

            $data = json_decode((string) $res->getBody(), true);
            $key = $data['key'] ?? null;

            return is_string($key) && $key !== '' ? $key : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Resolve a key: prefer direct env var, else try aigate provider id.
     * Also writes the fetched key back via putenv so OpenAiCompatibleClient's getenv read succeeds.
     */
    public static function resolveKey(string $envVar, string $aigateProvider): ?string
    {
        $direct = trim((string) getenv($envVar));
        if ($direct !== '') {
            return $direct;
        }

        $fetched = self::fetchKey($aigateProvider);
        if ($fetched !== null) {
            putenv("{$envVar}={$fetched}");
            $_ENV[$envVar] = $fetched;
        }

        return $fetched;
    }
}
