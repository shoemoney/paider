<?php

namespace App\Support;

use App\Storage\ProjectEnv;

/**
 * Decides whether a model-supplied URL may be fetched, and pins the answer to specific IPs.
 *
 * The threat is not the open internet — it is that this process runs on a LAN holding a git
 * host, a credential vault and a NAS, all reachable at private addresses with no authentication
 * beyond being on the network. A fetch tool inside an agent loop is a request generator that a
 * prompt-injected model can aim, so anything that resolves off the public internet is refused.
 *
 * Returning the resolved IPs is part of the contract, not a convenience: validating a hostname
 * and then letting the HTTP client resolve it again is a TOCTOU (DNS rebinding) — the second
 * lookup can answer 127.0.0.1. Callers are expected to pin the connection to these exact IPs.
 */
final class UrlGuard
{
    public const MAX_REDIRECTS = 5;

    /** Comma-separated hostnames the user vouches for, e.g. `git.example.com,searx.example.com`. */
    public const ALLOW_VAR = 'PAIDER_FETCH_ALLOW';

    /**
     * Carrier-grade NAT. Not covered by PHP's reserved-range filter, and it is the range
     * Tailscale hands out — on this network that is a route to every other machine.
     */
    private const CGNAT = ['100.64.0.0', '100.127.255.255'];

    /**
     * @return array{ok: true, host: string, port: int, ips: array<int, string>}|array{ok: false, reason: string}
     */
    public static function inspect(string $url): array
    {
        $parts = parse_url($url);

        if ($parts === false || ! is_array($parts)) {
            return self::deny('not a parsable URL');
        }

        $scheme = strtolower($parts['scheme'] ?? '');

        if (! in_array($scheme, ['http', 'https'], true)) {
            return self::deny("only http and https are allowed, got '{$scheme}'");
        }

        // user:pass@host smuggles credentials to whatever the host turns out to be, and is
        // also the classic way to make a URL read as one host while resolving as another.
        if (isset($parts['user']) || isset($parts['pass'])) {
            return self::deny('credentials in the URL are not allowed');
        }

        $host = $parts['host'] ?? '';

        if ($host === '') {
            return self::deny('no host in the URL');
        }

        $host = trim($host, '[]');
        $port = $parts['port'] ?? ($scheme === 'https' ? 443 : 80);

        $ips = self::resolve($host);

        if ($ips === []) {
            return self::deny("could not resolve '{$host}'");
        }

        // EVERY answer must be public. A host that returns one public and one private address
        // is a rebinding attempt, and which one gets used is up to the resolver.
        //
        // Unless the user has explicitly named this host. Self-hosted infrastructure — a Forgejo
        // instance, a local SearXNG — legitimately resolves to a private address, and refusing
        // it forever is the wrong default for someone who owns the network. The exemption is
        // per-HOST and opt-in, never a blanket "allow private": a redirect from an allowlisted
        // host to some other private address is checked against the list on its own hop and
        // still refused.
        if (! self::isAllowlisted($host)) {
            foreach ($ips as $ip) {
                if (! self::isPublic($ip)) {
                    return self::deny("'{$host}' resolves to the non-public address {$ip}"
                        .' (add it to '.self::ALLOW_VAR.' if you own it)');
                }
            }
        }

        return ['ok' => true, 'host' => $host, 'port' => $port, 'ips' => $ips];
    }

    /**
     * True only for addresses on the public internet. Loopback, link-local (which is where
     * cloud metadata services live), RFC1918, unique-local IPv6 and CGNAT are all refused.
     */
    public static function isPublic(string $ip): bool
    {
        // An IPv4-mapped IPv6 address (::ffff:192.168.1.10) is a v4 address wearing a v6 hat;
        // the v6 filters do not see the embedded octets, so unwrap before deciding.
        if (preg_match('/^::ffff:(\d+\.\d+\.\d+\.\d+)$/i', $ip, $m)) {
            $ip = $m[1];
        }

        $public = filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE,
        );

        if ($public === false) {
            return false;
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            $long = ip2long($ip);

            if ($long !== false
                && $long >= ip2long(self::CGNAT[0])
                && $long <= ip2long(self::CGNAT[1])) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return array<int, string> every A/AAAA answer, or the literal itself when the host is
     *                            already an IP (no lookup to be poisoned in that case)
     */
    private static function resolve(string $host): array
    {
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return [$host];
        }

        $records = @dns_get_record($host, DNS_A | DNS_AAAA) ?: [];

        $ips = array_values(array_filter(array_map(
            fn (array $record) => $record['ip'] ?? $record['ipv6'] ?? null,
            $records,
        )));

        // Fall back to the resolver the OS actually uses for the hosts file and other
        // non-DNS sources, which dns_get_record bypasses entirely.
        if ($ips === []) {
            $ips = gethostbynamel($host) ?: [];
        }

        return $ips;
    }

    /**
     * Hostnames the user has explicitly vouched for, from PAIDER_FETCH_ALLOW.
     *
     * EXACT, case-insensitive hostname match — no wildcards and no suffix matching. A naive
     * `str_ends_with($host, $entry)` would make an entry of `example.com` also match
     * `notexample.com`, and a wildcard would hand one typo'd entry a whole zone. Anyone needing
     * several subdomains lists them.
     *
     * @return array<int, string>
     */
    public static function allowlist(): array
    {
        $raw = ProjectEnv::get(self::ALLOW_VAR);

        if ($raw === null || trim($raw) === '') {
            return [];
        }

        return array_values(array_filter(
            array_map(fn (string $entry) => strtolower(trim($entry, " \t\n\r\0\x0B[]")), explode(',', $raw)),
            fn (string $entry) => $entry !== '',
        ));
    }

    private static function isAllowlisted(string $host): bool
    {
        return in_array(strtolower($host), self::allowlist(), true);
    }

    /** @return array{ok: false, reason: string} */
    private static function deny(string $reason): array
    {
        return ['ok' => false, 'reason' => $reason];
    }
}
