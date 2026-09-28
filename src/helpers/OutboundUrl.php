<?php

declare(strict_types=1);

namespace bytesof\formable\helpers;

use Closure;
use Craft;

/**
 * Decides whether the server may call a URL an integration was configured with.
 *
 * A webhook URL is typed in by whoever holds `formable:manageIntegrations`, a
 * delegable permission that is not an admin account. Without a guard that
 * person can point the server at anything it can reach from inside the
 * network - a cloud metadata endpoint, an admin panel bound to localhost, a
 * database's HTTP port. A URL is allowed only if every address its host
 * resolves to is public, unless the site's allowlist names it.
 *
 * See internal/decisions/0106-outbound-integration-urls-may-not-reach-private-addresses.md.
 *
 * @internal
 */
final class OutboundUrl
{
    /**
     * Ranges that are never a legitimate public destination. PHP's own
     * `FILTER_FLAG_NO_PRIV_RANGE`/`NO_RES_RANGE` are not used because which
     * ranges they cover differs by PHP version and misses carrier-grade NAT
     * and the IPv6 forms that embed an IPv4 address.
     *
     * @var array<int, string>
     */
    private const BLOCKED = [
        '0.0.0.0/8',
        '10.0.0.0/8',
        '100.64.0.0/10',
        '127.0.0.0/8',
        '169.254.0.0/16',
        '172.16.0.0/12',
        '192.0.0.0/24',
        '192.0.2.0/24',
        '192.168.0.0/16',
        '198.18.0.0/15',
        '198.51.100.0/24',
        '203.0.113.0/24',
        '224.0.0.0/4',
        '240.0.0.0/4',
        '::/128',
        '::1/128',
        '100::/64',
        '2001:db8::/32',
        'fc00::/7',
        'fe80::/10',
        'fec0::/10',
        'ff00::/8',
    ];

    /**
     * Why a URL may not be called, or null when it may.
     *
     * @param array<int, string> $allowed Hostnames, addresses and CIDR ranges the
     *   site has chosen to let integrations reach regardless.
     * @param Closure(string): array<int, string>|null $resolver Maps a host to the
     *   addresses it resolves to; the system resolver when null. Injectable so
     *   the rules can be tested without DNS.
     */
    public static function violation(string $url, array $allowed = [], ?Closure $resolver = null): ?string
    {
        $parts = parse_url($url);
        $scheme = strtolower((string)($parts['scheme'] ?? ''));
        $host = strtolower(trim((string)($parts['host'] ?? ''), '[]'));

        if (!in_array($scheme, ['http', 'https'], true) || $host === '') {
            return Craft::t('formable', 'The URL must start with http:// or https://.');
        }

        if (isset($parts['user']) || isset($parts['pass'])) {
            return Craft::t('formable', 'The URL must not contain a username or password.');
        }

        if (self::hostIsAllowed($host, $allowed)) {
            return null;
        }

        $addresses = self::addressesFor($host, $resolver);

        if ($addresses === []) {
            return Craft::t('formable', 'The host “{host}” could not be resolved.', ['host' => $host]);
        }

        foreach ($addresses as $address) {
            if (self::isBlocked($address) && !self::addressIsAllowed($address, $allowed)) {
                return Craft::t('formable', '“{host}” resolves to {address}, a private or reserved address.', [
                    'host' => $host,
                    'address' => $address,
                ]);
            }
        }

        return null;
    }

    /**
     * The addresses a checked host resolves to, for pinning the connection to
     * exactly what was validated so a DNS answer can't change in between.
     *
     * @param Closure(string): array<int, string>|null $resolver
     * @return array<int, string>
     */
    public static function addressesFor(string $host, ?Closure $resolver = null): array
    {
        $host = strtolower(trim($host, '[]'));

        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return [$host];
        }

        return array_values(array_unique($resolver !== null ? $resolver($host) : self::resolve($host)));
    }

    /**
     * Whether an address falls in a range a public service never lives in.
     */
    public static function isBlocked(string $address): bool
    {
        $packed = @inet_pton($address);

        if ($packed === false) {
            return true;
        }

        $embedded = self::embeddedIpv4($packed);

        if ($embedded !== null) {
            return self::isBlocked($embedded);
        }

        foreach (self::BLOCKED as $range) {
            if (self::inRange($packed, $range)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The allowlist as a setting stores it - one entry per line - as a clean list.
     *
     * @return array<int, string>
     */
    public static function parseAllowlist(string $setting): array
    {
        $entries = preg_split('/\R/', $setting) ?: [];

        return array_values(array_filter(array_map(
            static fn(string $entry): string => strtolower(trim($entry)),
            $entries,
        ), static fn(string $entry): bool => $entry !== ''));
    }

    /**
     * @param array<int, string> $allowed
     */
    private static function hostIsAllowed(string $host, array $allowed): bool
    {
        return in_array($host, $allowed, true);
    }

    /**
     * @param array<int, string> $allowed
     */
    private static function addressIsAllowed(string $address, array $allowed): bool
    {
        $packed = @inet_pton($address);

        if ($packed === false) {
            return false;
        }

        foreach ($allowed as $entry) {
            if (str_contains($entry, '/') || filter_var($entry, FILTER_VALIDATE_IP) !== false) {
                if (self::inRange($packed, str_contains($entry, '/') ? $entry : self::hostRange($entry))) {
                    return true;
                }
            }
        }

        return false;
    }

    private static function hostRange(string $address): string
    {
        return $address . (str_contains($address, ':') ? '/128' : '/32');
    }

    /**
     * An IPv4 address carried inside an IPv6 one - `::ffff:a.b.c.d` and the
     * NAT64 and 6to4 forms - so `::ffff:127.0.0.1` can't stand in for loopback.
     */
    private static function embeddedIpv4(string $packed): ?string
    {
        if (strlen($packed) !== 16) {
            return null;
        }

        $mapped = str_repeat("\0", 10) . "\xff\xff";

        if (str_starts_with($packed, $mapped)) {
            return inet_ntop(substr($packed, 12));
        }

        if (str_starts_with($packed, "\x00\x64\xff\x9b" . str_repeat("\0", 8))) {
            return inet_ntop(substr($packed, 12));
        }

        if (str_starts_with($packed, "\x20\x02")) {
            return inet_ntop(substr($packed, 2, 4));
        }

        return null;
    }

    private static function inRange(string $packed, string $cidr): bool
    {
        [$base, $bits] = explode('/', $cidr, 2) + [1 => ''];
        $baseBytes = @inet_pton($base);

        if ($baseBytes === false || strlen($baseBytes) !== strlen($packed) || !ctype_digit($bits)) {
            return false;
        }

        $bits = (int)$bits;
        $whole = intdiv($bits, 8);

        if (substr($packed, 0, $whole) !== substr($baseBytes, 0, $whole)) {
            return false;
        }

        $rest = $bits % 8;

        if ($rest === 0) {
            return true;
        }

        $mask = (0xff << (8 - $rest)) & 0xff;

        return (ord($packed[$whole]) & $mask) === (ord($baseBytes[$whole]) & $mask);
    }

    /**
     * @return array<int, string>
     */
    private static function resolve(string $host): array
    {
        $addresses = gethostbynamel($host) ?: [];

        $records = @dns_get_record($host, DNS_AAAA) ?: [];

        foreach ($records as $record) {
            if (isset($record['ipv6'])) {
                $addresses[] = (string)$record['ipv6'];
            }
        }

        return $addresses;
    }
}
