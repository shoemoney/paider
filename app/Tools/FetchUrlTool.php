<?php

namespace App\Tools;

use App\Support\UrlGuard;
use App\Tools\Contracts\Tool;
use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * Fetches a public web page as text.
 *
 * UI-agnostic in the same way ShellTool is: the caller resolves the approval decision and
 * passes it in as $input['approval']. Egress is gated for the same reason command execution
 * is — it acts outside the project and cannot be undone once it has happened.
 */
class FetchUrlTool implements Tool
{
    private const MAX_BYTES = 262_144;

    private ClientInterface $http;

    public function __construct(
        private readonly int $timeoutSeconds = 15,
        ?ClientInterface $httpClient = null,
    ) {
        $this->http = $httpClient ?? new Client;
    }

    public function name(): string
    {
        return 'fetch_url';
    }

    public function description(): string
    {
        return 'Fetches a public http(s) URL and returns its text. Requires an approval decision. '
            .'Private, loopback and link-local addresses are refused.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'url' => ['type' => 'string'],
                'approval' => ['type' => 'string', 'enum' => ['allow-once', 'allow-session', 'deny']],
            ],
            'required' => ['url'],
        ];
    }

    // ponytail: unused, only read/write/patch/git use the out-of-band signal — see Tool::execute() doc
    public function execute(array $input, bool $approved = false): ToolResult
    {
        if (! is_string($input['url'] ?? null) || $input['url'] === '') {
            return ToolResult::fail('url must be a string');
        }

        if (! array_key_exists('approval', $input)) {
            return ToolResult::fail('approval required', ['needs_approval' => true]);
        }

        if ($input['approval'] === 'deny') {
            return ToolResult::fail('denied');
        }

        if (! in_array($input['approval'], ['allow-once', 'allow-session'], true)) {
            return ToolResult::fail('approval required', ['needs_approval' => true]);
        }

        return $this->follow($input['url']);
    }

    /**
     * Redirects are followed by hand rather than by the HTTP client. The client's own
     * redirect following would re-resolve and re-connect without consulting UrlGuard, so a
     * public page answering `302 -> http://192.168.1.10/` would walk straight onto the LAN —
     * the guard has to run again on every hop, not just the one the model supplied.
     */
    private function follow(string $url): ToolResult
    {
        $seen = [];

        for ($hop = 0; $hop <= UrlGuard::MAX_REDIRECTS; $hop++) {
            $verdict = UrlGuard::inspect($url);

            if ($verdict['ok'] !== true) {
                return ToolResult::fail("refused: {$verdict['reason']}");
            }

            if (isset($seen[$url])) {
                return ToolResult::fail('refused: redirect loop');
            }

            $seen[$url] = true;

            try {
                $response = $this->http->request('GET', $url, $this->options($verdict));
            } catch (\Throwable $e) {
                return ToolResult::fail('fetch failed: '.$e->getMessage());
            }

            $status = $response->getStatusCode();
            $location = $response->getHeaderLine('Location');

            if ($status >= 300 && $status < 400 && $location !== '') {
                // A Location may be relative; resolving it against the CURRENT url is what
                // makes the next guard check meaningful.
                $url = $this->absolutize($url, $location);

                continue;
            }

            return $this->body($response, $status);
        }

        return ToolResult::fail('refused: too many redirects');
    }

    /** @param array{host: string, port: int, ips: array<int, string>} $verdict */
    private function options(array $verdict): array
    {
        return [
            'timeout' => $this->timeoutSeconds,
            'allow_redirects' => false,
            'headers' => ['User-Agent' => 'paider/0.2 (+https://paider.dev)'],
            'stream' => true,
            'http_errors' => false,
            // Pin the connection to the addresses the guard actually validated. Without this
            // the client performs its own lookup, and the name it re-resolves is free to
            // answer with something private the second time (DNS rebinding).
            'curl' => [
                CURLOPT_RESOLVE => array_map(
                    fn (string $ip) => "{$verdict['host']}:{$verdict['port']}:{$ip}",
                    $verdict['ips'],
                ),
            ],
        ];
    }

    private function body(ResponseInterface $response, int $status): ToolResult
    {
        // Read a bounded prefix rather than the whole body: the size is chosen by whoever
        // controls the page, and an unbounded read is a memory exhaustion handed to a stranger.
        $stream = $response->getBody();
        $text = '';

        while (! $stream->eof() && strlen($text) < self::MAX_BYTES) {
            $chunk = $stream->read(self::MAX_BYTES - strlen($text));

            if ($chunk === '') {
                break;
            }

            $text .= $chunk;
        }

        $truncated = strlen($text) >= self::MAX_BYTES;

        if (str_contains(strtolower($response->getHeaderLine('Content-Type')), 'html')) {
            $text = $this->toText($text);
        }

        if ($status >= 400) {
            return ToolResult::fail("HTTP {$status}\n{$text}", ['status' => $status]);
        }

        return ToolResult::ok(
            $truncated ? $text."\n\n[truncated at ".self::MAX_BYTES.' bytes]' : $text,
            ['status' => $status, 'truncated' => $truncated],
        );
    }

    private function toText(string $html): string
    {
        // script/style bodies are not prose and would otherwise survive strip_tags as content.
        $html = preg_replace('#<(script|style)\b[^>]*>.*?</\1>#is', ' ', $html) ?? $html;
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim(preg_replace('/\n{3,}/', "\n\n", preg_replace('/[ \t]+/', ' ', $text) ?? $text) ?? $text);
    }

    private function absolutize(string $base, string $location): string
    {
        if (parse_url($location, PHP_URL_SCHEME) !== null) {
            return $location;
        }

        $parts = parse_url($base);
        $root = ($parts['scheme'] ?? 'https').'://'.($parts['host'] ?? '')
            .(isset($parts['port']) ? ':'.$parts['port'] : '');

        if (str_starts_with($location, '/')) {
            return $root.$location;
        }

        $path = $parts['path'] ?? '/';

        return $root.substr($path, 0, (strrpos($path, '/') ?: 0) + 1).$location;
    }
}
