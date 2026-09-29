<?php

namespace App\Providers;

use App\Tools\McpTool;
use App\Tools\ToolResult;
use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use RuntimeException;

/** HTTP client for Mozilla mcpd. Opt-in endpoint comes only from the process environment. */
final class McpdClient
{
    private ClientInterface $http;

    public function __construct(string $url = 'http://127.0.0.1:8090', ?ClientInterface $http = null)
    {
        $parts = parse_url($url);
        if (! $parts || ! in_array($parts['scheme'] ?? '', ['http', 'https'], true)
            || empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])
            || isset($parts['query']) || isset($parts['fragment'])) {
            throw new RuntimeException('PAIDER_MCPD_URL must be an HTTP(S) endpoint without credentials, query or fragment');
        }
        $this->http = $http ?? new Client([
            'base_uri' => rtrim($url, '/').'/',
            'timeout' => 60, 'connect_timeout' => 5,
            'allow_redirects' => false,
            'headers' => ['Accept' => 'application/json, application/problem+json'],
        ]);
    }

    public static function toolsFromEnvironment(): array
    {
        $url = getenv('PAIDER_MCPD_URL');

        return $url === false || $url === '' ? [] : (new self($url))->tools();
    }

    public function tools(): array
    {
        $servers = $this->request('GET', 'api/v1/servers');
        if (! is_array($servers) || ! array_is_list($servers)) {
            throw new RuntimeException('mcpd returned an invalid server list');
        }
        $tools = [];
        foreach ($servers as $server) {
            if (! is_string($server) || $server === '') {
                throw new RuntimeException('mcpd returned an invalid server name');
            }
            $path = 'api/v1/servers/'.rawurlencode($server).'/tools';
            $response = $this->request('GET', $path);
            if (! is_array($response) || ! is_array($response['tools'] ?? null)) {
                throw new RuntimeException('mcpd returned an invalid tool list');
            }
            foreach ($response['tools'] as $definition) {
                if (! is_array($definition) || ! is_string($definition['name'] ?? null)
                    || $definition['name'] === '' || ! is_array($definition['inputSchema'] ?? null)) {
                    throw new RuntimeException('mcpd returned an invalid tool definition');
                }
                $remoteName = $definition['name'];
                // Hash the original pair so punctuation normalization never aliases two tools.
                $name = 'mcpd__'.substr(preg_replace('/[^a-zA-Z0-9_]/', '_', $server.'_'.$remoteName), 0, 42)
                    .'_'.substr(hash('sha256', json_encode([$server, $remoteName], JSON_THROW_ON_ERROR)), 0, 12);
                $tools[] = new McpTool($name, $definition['description'] ?? "$server/$remoteName",
                    $definition['inputSchema'], function (array $input, bool $approved) use ($path, $remoteName): ToolResult {
                        if (! $approved) {
                            return ToolResult::fail('mcpd tool requires approval', ['needs_approval' => true]);
                        }
                        try {
                            $result = $this->request('POST', $path.'/'.rawurlencode($remoteName), $input);
                            if (! is_string($result)) {
                                throw new RuntimeException('mcpd returned an invalid tool result');
                            }

                            return ToolResult::ok($result);
                        } catch (\Throwable $e) {
                            return ToolResult::fail('mcpd call failed: '.$e->getMessage());
                        }
                    });
            }
        }

        return $tools;
    }

    private function request(string $method, string $path, ?array $arguments = null): mixed
    {
        $options = ['allow_redirects' => false, 'http_errors' => false];
        if ($arguments !== null) {
            $options['json'] = (object) $arguments;
        }
        $response = $this->http->request($method, $path, $options);
        if ($response->getStatusCode() < 200 || $response->getStatusCode() >= 300) {
            throw new RuntimeException('HTTP '.$response->getStatusCode().' from mcpd');
        }
        $body = (string) $response->getBody();
        if ($path === 'api/v1/servers' && ! is_array(json_decode($body, false, 512, JSON_THROW_ON_ERROR))) {
            throw new RuntimeException('mcpd returned an invalid server list');
        }

        return json_decode($body, true, 512, JSON_THROW_ON_ERROR);
    }
}
