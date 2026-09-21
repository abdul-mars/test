<?php
declare(strict_types=1);

namespace QRoute\Http;

use QRoute\Core\Config;

/**
 * Immutable-ish view of the incoming HTTP request.
 *
 * Client IP resolution deliberately does NOT trust X-Forwarded-For by
 * default. Behind a proxy you must set TRUSTED_PROXIES, otherwise any
 * visitor could spoof their IP and defeat rate limiting.
 */
final class Request
{
    /** @var array<string,string> */
    private array $headers;

    private function __construct(
        public readonly string $method,
        public readonly string $path,
        /** @var array<string,mixed> */ public readonly array $query,
        /** @var array<string,mixed> */ public readonly array $post,
        array $headers,
        public readonly string $rawBody,
        public readonly bool $secure,
        public readonly string $host,
    ) {
        $this->headers = $headers;
    }

    public static function capture(): self
    {
        $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        $uri = (string) ($_SERVER['REQUEST_URI'] ?? '/');
        $path = parse_url($uri, PHP_URL_PATH);
        $path = is_string($path) ? rawurldecode($path) : '/';
        if ($path === '' ) {
            $path = '/';
        }

        $headers = [];
        foreach ($_SERVER as $k => $v) {
            if (str_starts_with((string) $k, 'HTTP_')) {
                $name = strtolower(str_replace('_', '-', substr((string) $k, 5)));
                $headers[$name] = (string) $v;
            }
        }
        foreach (['CONTENT_TYPE' => 'content-type', 'CONTENT_LENGTH' => 'content-length'] as $sk => $hk) {
            if (isset($_SERVER[$sk])) {
                $headers[$hk] = (string) $_SERVER[$sk];
            }
        }

        $https = ($_SERVER['HTTPS'] ?? '') !== '' && strtolower((string) $_SERVER['HTTPS']) !== 'off';
        if (!$https && self::proxyTrusted() && (($headers['x-forwarded-proto'] ?? '') === 'https')) {
            $https = true;
        }

        $body = '';
        $ctype = strtolower($headers['content-type'] ?? '');
        if (!str_starts_with($ctype, 'application/x-www-form-urlencoded')
            && !str_starts_with($ctype, 'multipart/form-data')) {
            $body = (string) file_get_contents('php://input');
        }

        return new self(
            $method,
            $path,
            $_GET,
            $_POST,
            $headers,
            $body,
            $https,
            (string) ($_SERVER['HTTP_HOST'] ?? 'localhost')
        );
    }

    /** Builds a request by hand. Used by the test suite. */
    public static function fake(string $method, string $path, array $post = [], array $query = [], array $headers = []): self
    {
        return new self(strtoupper($method), $path, $query, $post, $headers, '', true, 'localhost');
    }

    public function header(string $name, string $default = ''): string
    {
        return $this->headers[strtolower($name)] ?? $default;
    }

    public function input(string $key, ?string $default = null): ?string
    {
        $v = $this->post[$key] ?? $this->query[$key] ?? null;
        if (is_array($v)) {
            return $default;
        }
        return $v === null ? $default : trim((string) $v);
    }

    /** @return list<string> */
    public function inputArray(string $key): array
    {
        $v = $this->post[$key] ?? $this->query[$key] ?? [];
        if (!is_array($v)) {
            return $v === '' ? [] : [(string) $v];
        }
        $out = [];
        foreach ($v as $item) {
            if (!is_array($item)) {
                $out[] = (string) $item;
            }
        }
        return $out;
    }

    public function boolean(string $key): bool
    {
        $v = $this->input($key, '');
        return in_array(strtolower((string) $v), ['1', 'true', 'on', 'yes'], true);
    }

    /** @return array<string,mixed> */
    public function json(): array
    {
        if ($this->rawBody === '') {
            return [];
        }
        $data = json_decode($this->rawBody, true, 32, JSON_INVALID_UTF8_SUBSTITUTE);
        return is_array($data) ? $data : [];
    }

    public function userAgent(): string
    {
        return mb_substr($this->header('user-agent'), 0, 512);
    }

    public function refererHost(): string
    {
        $ref = $this->header('referer');
        if ($ref === '') {
            return '';
        }
        $host = parse_url($ref, PHP_URL_HOST);
        return is_string($host) ? mb_strtolower(mb_substr($host, 0, 191)) : '';
    }

    /** Preferred language as a two letter code, e.g. "en". */
    public function language(): string
    {
        $al = $this->header('accept-language');
        if ($al === '') {
            return '';
        }
        if (preg_match('/^\s*([a-zA-Z]{2,3})(?:-[a-zA-Z0-9]+)?/', $al, $m) === 1) {
            return strtolower($m[1]);
        }
        return '';
    }

    public function ip(): string
    {
        $remote = (string) ($_SERVER['REMOTE_ADDR'] ?? '127.0.0.1');
        if (!self::proxyTrusted()) {
            return $remote;
        }
        // Cloudflare's header is a single value and cannot be chained.
        $cf = $this->header('cf-connecting-ip');
        if ($cf !== '' && filter_var($cf, FILTER_VALIDATE_IP)) {
            return $cf;
        }
        $xff = $this->header('x-forwarded-for');
        if ($xff !== '') {
            $parts = array_map('trim', explode(',', $xff));
            $candidate = $parts[0] ?? '';
            if (filter_var($candidate, FILTER_VALIDATE_IP)) {
                return $candidate;
            }
        }
        return $remote;
    }

    public function isJsonRequest(): bool
    {
        return str_contains(strtolower($this->header('content-type')), 'json')
            || str_contains(strtolower($this->header('accept')), 'json');
    }

    public function baseUrl(): string
    {
        $configured = Config::get('APP_URL', '');
        if ($configured !== null && $configured !== '') {
            return rtrim($configured, '/');
        }
        return ($this->secure ? 'https://' : 'http://') . $this->host;
    }

    private static function proxyTrusted(): bool
    {
        $trusted = trim((string) Config::get('TRUSTED_PROXIES', ''));
        if ($trusted === '') {
            return false;
        }
        if ($trusted === '*') {
            return true;
        }
        $remote = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
        foreach (array_map('trim', explode(',', $trusted)) as $cidr) {
            if ($cidr !== '' && self::ipInCidr($remote, $cidr)) {
                return true;
            }
        }
        return false;
    }

    public static function ipInCidr(string $ip, string $cidr): bool
    {
        if (!str_contains($cidr, '/')) {
            return $ip === $cidr;
        }
        [$subnet, $bitsRaw] = explode('/', $cidr, 2);
        $bits = (int) $bitsRaw;
        $ipBin = @inet_pton($ip);
        $subBin = @inet_pton($subnet);
        if ($ipBin === false || $subBin === false || strlen($ipBin) !== strlen($subBin)) {
            return false;
        }
        $bytes = intdiv($bits, 8);
        $rem = $bits % 8;
        if ($bytes > 0 && substr($ipBin, 0, $bytes) !== substr($subBin, 0, $bytes)) {
            return false;
        }
        if ($rem === 0) {
            return true;
        }
        $mask = ~((1 << (8 - $rem)) - 1) & 0xFF;
        return (ord($ipBin[$bytes]) & $mask) === (ord($subBin[$bytes]) & $mask);
    }
}
