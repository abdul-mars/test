<?php
declare(strict_types=1);

namespace QRoute\Services;

use QRoute\Core\Config;
use QRoute\Http\Request;

/**
 * Destination URL validation.
 *
 * A redirector is an open-redirect machine by definition, so the danger is
 * not "can someone redirect elsewhere" (that is the product) but:
 *   1. script-bearing schemes (javascript:, data:, vbscript:) which would
 *      turn a scan into stored XSS on our own origin,
 *   2. destinations pointing at private/loopback/link-local addresses, which
 *      turn the service into a free internal network scanner for an attacker,
 *   3. redirect loops back into our own short domain.
 */
final class UrlValidator
{
    private const SAFE_SCHEMES = ['http', 'https', 'mailto', 'tel', 'sms', 'facetime', 'geo'];

    private const BLOCKED_SCHEMES = [
        'javascript', 'data', 'vbscript', 'file', 'about', 'blob',
        'jar', 'view-source', 'chrome', 'chrome-extension', 'resource',
    ];

    /** Ranges that must never be a redirect destination. */
    private const PRIVATE_RANGES = [
        '0.0.0.0/8', '10.0.0.0/8', '100.64.0.0/10', '127.0.0.0/8',
        '169.254.0.0/16', '172.16.0.0/12', '192.0.0.0/24', '192.168.0.0/16',
        '198.18.0.0/15', '224.0.0.0/4', '240.0.0.0/4',
        '::1/128', 'fc00::/7', 'fe80::/10', '::/128',
    ];

    public const MAX_LENGTH = 2048;

    /**
     * @return array{ok:bool,url:string,error:string}
     */
    public static function check(string $url, bool $allowCustomSchemes = false): array
    {
        $url = trim($url);

        // Strip control characters and whitespace that browsers ignore but
        // that let "java\tscript:" slip past a naive scheme check.
        $url = preg_replace('/[\x00-\x20\x7F]+/', '', $url) ?? '';

        if ($url === '') {
            return self::fail('Destination URL is required.');
        }
        if (strlen($url) > self::MAX_LENGTH) {
            return self::fail('Destination URL is too long (max ' . self::MAX_LENGTH . ' characters).');
        }
        if (!preg_match('//u', $url)) {
            return self::fail('Destination URL contains invalid characters.');
        }

        // A bare "example.com" is what people actually type. Assume https.
        if (!preg_match('#^[a-zA-Z][a-zA-Z0-9+.\-]*:#', $url)) {
            $url = 'https://' . ltrim($url, '/');
        }

        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        if ($scheme === '') {
            return self::fail('Destination URL is malformed.');
        }
        if (in_array($scheme, self::BLOCKED_SCHEMES, true)) {
            return self::fail("The \"{$scheme}:\" scheme is not allowed.");
        }
        if (!in_array($scheme, self::SAFE_SCHEMES, true) && !$allowCustomSchemes) {
            return self::fail(
                "Only " . implode(', ', self::SAFE_SCHEMES) . " links are allowed on your plan. "
                . "Upgrade to use app deep links."
            );
        }

        // Non-network schemes (mailto:, tel:) have no host to inspect.
        if (!in_array($scheme, ['http', 'https'], true)) {
            return ['ok' => true, 'url' => $url, 'error' => ''];
        }

        $parts = parse_url($url);
        if ($parts === false || !isset($parts['host']) || $parts['host'] === '') {
            return self::fail('Destination URL must include a hostname.');
        }

        $host = strtolower($parts['host']);

        // Userinfo in a URL ("https://evil.com@real.com") is a classic
        // phishing disguise. Refuse it outright.
        if (isset($parts['user']) || isset($parts['pass'])) {
            return self::fail('Destination URL must not contain credentials.');
        }

        if (!self::validHostname($host)) {
            return self::fail('Destination hostname is not valid.');
        }

        if (self::isPrivateHost($host)) {
            return self::fail('Destination points to a private or local address.');
        }

        if (self::isOwnHost($host)) {
            return self::fail('Destination cannot point back at QRoute (that would loop).');
        }

        return ['ok' => true, 'url' => $url, 'error' => ''];
    }

    public static function isPrivateHost(string $host): bool
    {
        $host = trim($host, '[]');

        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return self::ipIsPrivate($host);
        }

        // Hostnames that resolve locally by convention.
        if ($host === 'localhost' || str_ends_with($host, '.localhost')
            || str_ends_with($host, '.local') || str_ends_with($host, '.internal')
            || str_ends_with($host, '.home.arpa')) {
            return true;
        }

        // Decimal/octal/hex encoded IPv4 ("http://2130706433/") normalises to
        // a loopback address in every browser.
        if (preg_match('/^\d+$/', $host) === 1) {
            $long = (int) $host;
            if ($long >= 0 && $long <= 4294967295) {
                return self::ipIsPrivate(long2ip($long));
            }
        }

        if (Config::bool('BLOCK_PRIVATE_DNS', true)) {
            $records = @dns_get_record($host, DNS_A | DNS_AAAA);
            if (is_array($records)) {
                foreach ($records as $rec) {
                    $ip = $rec['ip'] ?? $rec['ipv6'] ?? null;
                    if (is_string($ip) && self::ipIsPrivate($ip)) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    public static function ipIsPrivate(string $ip): bool
    {
        foreach (self::PRIVATE_RANGES as $range) {
            if (Request::ipInCidr($ip, $range)) {
                return true;
            }
        }
        return false;
    }

    private static function isOwnHost(string $host): bool
    {
        $appUrl = (string) Config::get('APP_URL', '');
        if ($appUrl === '') {
            return false;
        }
        $own = strtolower((string) parse_url($appUrl, PHP_URL_HOST));
        return $own !== '' && $own === $host;
    }

    private static function validHostname(string $host): bool
    {
        if (filter_var(trim($host, '[]'), FILTER_VALIDATE_IP)) {
            return true;
        }
        if (strlen($host) > 253) {
            return false;
        }
        // Allow IDN by converting to punycode when the extension exists.
        if (function_exists('idn_to_ascii') && preg_match('/[^\x20-\x7E]/', $host) === 1) {
            $ascii = idn_to_ascii($host, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);
            if (is_string($ascii)) {
                $host = $ascii;
            }
        }
        return preg_match('/^(?=.{1,253}$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/i', $host) === 1
            || preg_match('/^[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?$/i', $host) === 1;
    }

    /** @return array{ok:bool,url:string,error:string} */
    private static function fail(string $message): array
    {
        return ['ok' => false, 'url' => '', 'error' => $message];
    }
}
