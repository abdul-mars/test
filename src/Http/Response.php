<?php
declare(strict_types=1);

namespace QRoute\Http;

/**
 * Response value object. Nothing is echoed until send() is called, which
 * keeps controllers testable and makes it impossible to emit a body before
 * the security headers.
 */
final class Response
{
    /** @param array<string,string> $headers */
    public function __construct(
        public string $body = '',
        public int $status = 200,
        public array $headers = [],
        /** @var list<array{0:string,1:string,2:array<string,mixed>}> */
        public array $cookies = []
    ) {
    }

    public static function html(string $body, int $status = 200): self
    {
        return new self($body, $status, ['Content-Type' => 'text/html; charset=UTF-8']);
    }

    public static function text(string $body, int $status = 200): self
    {
        return new self($body, $status, ['Content-Type' => 'text/plain; charset=UTF-8']);
    }

    public static function json(mixed $data, int $status = 200): self
    {
        $body = json_encode(
            $data,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
        );
        return new self($body === false ? '{}' : $body, $status, [
            'Content-Type' => 'application/json; charset=UTF-8',
        ]);
    }

    public static function redirect(string $to, int $status = 302): self
    {
        return new self('', $status, ['Location' => $to]);
    }

    /** @param array<string,string> $extra */
    public static function raw(string $body, string $contentType, int $status = 200, array $extra = []): self
    {
        return new self($body, $status, ['Content-Type' => $contentType] + $extra);
    }

    public function withHeader(string $name, string $value): self
    {
        $this->headers[$name] = $value;
        return $this;
    }

    /** @param array<string,mixed> $options */
    public function withCookie(string $name, string $value, array $options): self
    {
        $this->cookies[] = [$name, $value, $options];
        return $this;
    }

    /** Marks the response as private and uncacheable. */
    public function noStore(): self
    {
        $this->headers['Cache-Control'] = 'no-store, no-cache, must-revalidate, private';
        $this->headers['Pragma'] = 'no-cache';
        return $this;
    }

    /**
     * Removes the characters that could split a header and inject another.
     * Applied at send time so a value can be inspected unmodified before
     * it goes out.
     */
    public static function sanitizeHeaderValue(string $value): string
    {
        return str_replace(["\r", "\n", "\0"], '', $value);
    }

    /**
     * @param bool $withBody false for a HEAD request, where the headers
     *                       must match a GET but the body is omitted
     */
    public function send(bool $withBody = true): void
    {
        if (!headers_sent()) {
            http_response_code($this->status);
            foreach ($this->headers as $name => $value) {
                header($name . ': ' . self::sanitizeHeaderValue($value), true);
            }
            foreach ($this->cookies as [$name, $value, $options]) {
                setcookie($name, $value, $options);
            }
        }
        if ($withBody) {
            echo $this->body;
        }
    }
}
