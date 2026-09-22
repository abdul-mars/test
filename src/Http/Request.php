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
        /** Sub-directory the app is served from, e.g. "/qroute/public" or "". */
        public readonly string $basePath = '',
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

        // Dropping the project into htdocs and browsing to
        // localhost/qroute/public is the most natural thing to do on XAMPP,
        // so the app has to work from a sub-directory as well as from a
        // document root of its own. Routes are matched against the path
        // with that prefix removed, and generated URLs put it back.
        $basePath = self::detectBasePath();
        if ($basePath !== '' && str_starts_with($path, $basePath)) {
            $path = substr($path, strlen($basePath));
            if ($path === '' || $path[0] !== '/') {
                $path = '/' . $path;
            }
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
            (string) ($_SERVER['HTTP_HOST'] ?? 'localhost'),
            $basePath
        );
    }

    /**
     * Works out the sub-directory the front controller is served from.
     *
     * SCRIPT_NAME is "/qroute/public/index.php" when the project sits in a
     * folder under the document root, and "/index.php" when the web server
     * points at public/ directly. The directory part is the prefix every
     * generated URL needs.
     */
    private static function detectBasePath(): string
    {
        $script = (string) ($_SERVER['SCRIPT_NAME'] ?? '');
        if ($script === '') {
            return '';
        }
        $dir = str_replace('\\', '/', dirname($script));
        if ($dir === '/' || $dir === '.' || $dir === '\\') {
            return '';
        }
        $dir = rtrim($dir, '/');

        // SCRIPT_NAME comes from the server, and this value is printed into
        // every link on every page. Accept only a plain path so it can never
        // carry markup or traverse upwards.
        if (preg_match('#^(/[A-Za-z0-9_.\-~]+)+$#', $dir) !== 1 || str_contains($dir, '..')) {
            return '';
        }
        return $dir;
    }

    /** Builds a request by hand. Used by the test suite. */
    public static function fake(
        string $method,
        string $path,
        array $post = [],
        array $query = [],
        array $headers = [],
        string $basePath = ''
    ): self {
        return new self(strtoupper($method), $path, $query, $post, $headers, '', true, 'localhost', $basePath);
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
        return ($this->secure ? 'https://' : 'http://') . $this->host . $this->basePath;
    }

    /**
     * Turns an application path such as "/login" into one the browser can
     * follow, adding the sub-directory prefix when there is one.
     */
    public function url(string $path): string
    {
        if ($path === '' ) {
            return $this->basePath === '' ? '/' : $this->basePath;
        }
        if (preg_match('#^[a-z][a-z0-9+.\-]*:|^//#i', $path) === 1) {
            return $path; // already absolute
        }
        if ($path[0] !== '/') {
            $path = '/' . $path;
        }
        return $this->basePath . $path;
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
