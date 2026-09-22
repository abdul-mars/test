<?php
declare(strict_types=1);

namespace QRoute\Core;

/**
 * Plain PHP templates with escaping on by default.
 *
 * No template engine: PHP is one, the output is small, and every value
 * printed in a view goes through e() unless a developer deliberately
 * writes raw(), which makes unescaped output obvious in review.
 */
final class View
{
    private static string $nonce = '';
    /** @var array<string,mixed> */
    private static array $shared = [];

    public static function setNonce(string $nonce): void
    {
        self::$nonce = $nonce;
    }

    public static function nonce(): string
    {
        return self::$nonce;
    }

    public static function share(string $key, mixed $value): void
    {
        self::$shared[$key] = $value;
    }

    /** @param array<string,mixed> $data */
    public static function render(string $template, array $data = []): string
    {
        $path = __DIR__ . '/../views/' . str_replace(['..', "\0"], '', $template) . '.php';
        if (!is_file($path)) {
            throw new \RuntimeException("View not found: {$template}");
        }

        $data = array_merge(self::$shared, $data);
        // Views print this in front of every link. Guarantee it exists so a
        // view rendered outside a request cycle cannot emit a warning.
        $data['basePath'] ??= '';
        extract($data, EXTR_SKIP);
        $nonce = self::$nonce;

        ob_start();
        try {
            require $path;
        } catch (\Throwable $e) {
            ob_end_clean();
            throw $e;
        }
        return (string) ob_get_clean();
    }

    /**
     * Renders a page inside the main layout.
     *
     * @param array<string,mixed> $data
     */
    public static function page(string $template, array $data = []): string
    {
        $content = self::render($template, $data);
        return self::render('layout/main', array_merge($data, ['content' => $content]));
    }

    public static function e(mixed $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
    }

    /** Escapes for use inside a JavaScript string or JSON block. */
    public static function json(mixed $value): string
    {
        $json = json_encode(
            $value,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG
            | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_INVALID_UTF8_SUBSTITUTE
        );
        return $json === false ? 'null' : $json;
    }

    /** Human friendly relative time, e.g. "3 minutes ago". */
    public static function ago(?int $timestamp, ?int $now = null): string
    {
        if ($timestamp === null || $timestamp <= 0) {
            return 'never';
        }
        $diff = max(0, ($now ?? time()) - $timestamp);
        return match (true) {
            $diff < 60      => 'just now',
            $diff < 3600    => intdiv($diff, 60) . ' min ago',
            $diff < 86400   => intdiv($diff, 3600) . ' hr ago',
            $diff < 2592000 => intdiv($diff, 86400) . ' days ago',
            default         => gmdate('j M Y', $timestamp),
        };
    }

    public static function number(int $n): string
    {
        if ($n < 1000) {
            return (string) $n;
        }
        if ($n < 1000000) {
            $v = $n / 1000;
            return ($v < 10 ? number_format($v, 1) : (string) (int) $v) . 'k';
        }
        return number_format($n / 1000000, 1) . 'M';
    }
}
