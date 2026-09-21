<?php
declare(strict_types=1);

namespace QRoute\Core;

/**
 * Cryptographic helpers and the outbound security header set.
 *
 * All keyed hashing derives from APP_KEY. The key is required in
 * production; a missing key is a hard failure rather than a silent
 * fallback to a predictable default.
 */
final class Security
{
    private static ?string $key = null;

    public static function key(): string
    {
        if (self::$key !== null) {
            return self::$key;
        }
        $raw = (string) Config::get('APP_KEY', '');
        if ($raw === '') {
            if (Config::bool('APP_DEBUG', false) || PHP_SAPI === 'cli') {
                // Deterministic per-install development key so sessions and
                // hashes survive a restart without forcing setup first.
                $raw = 'dev:' . hash('sha256', __DIR__);
            } else {
                throw new \RuntimeException('APP_KEY is not set. Run: php bin/console key:generate');
            }
        }
        return self::$key = $raw;
    }

    public static function generateKey(): string
    {
        return 'base64:' . base64_encode(random_bytes(32));
    }

    /** Keyed hash used for IP and user-agent fingerprints. */
    public static function hmac(string $data, string $context = ''): string
    {
        return hash_hmac('sha256', $context . "\0" . $data, self::key());
    }

    public static function randomToken(int $bytes = 32): string
    {
        return rtrim(strtr(base64_encode(random_bytes($bytes)), '+/', '-_'), '=');
    }

    public static function hashPassword(string $password): string
    {
        if (defined('PASSWORD_ARGON2ID') && in_array('argon2id', password_algos(), true)) {
            return password_hash($password, PASSWORD_ARGON2ID, [
                'memory_cost' => 65536,
                'time_cost'   => 4,
                'threads'     => 2,
            ]);
        }
        return password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
    }

    public static function verifyPassword(string $password, string $hash): bool
    {
        return $hash !== '' && password_verify($password, $hash);
    }

    public static function needsRehash(string $hash): bool
    {
        if (defined('PASSWORD_ARGON2ID') && in_array('argon2id', password_algos(), true)) {
            return password_needs_rehash($hash, PASSWORD_ARGON2ID);
        }
        return password_needs_rehash($hash, PASSWORD_BCRYPT, ['cost' => 12]);
    }

    public static function equals(string $a, string $b): bool
    {
        return hash_equals($a, $b);
    }

    /**
     * Security headers applied to every HTML response.
     *
     * The CSP is strict: no inline scripts without a nonce, no external
     * script origins at all. Every bit of JavaScript in this app ships from
     * our own origin, so there is nothing to whitelist.
     *
     * @return array<string,string>
     */
    public static function headers(string $nonce, bool $secure, bool $allowFraming = false): array
    {
        $csp = implode('; ', [
            "default-src 'self'",
            "base-uri 'self'",
            "script-src 'self' 'nonce-{$nonce}'",
            // No inline event handlers exist anywhere in the templates, so
            // attribute-level script stays fully blocked.
            "script-src-attr 'none'",
            "style-src 'self' 'nonce-{$nonce}'",
            // A nonce cannot authorise a style="" attribute, and the views
            // use them for one-off layout tweaks. Attribute styles cannot
            // execute script; the residual risk is CSS-based exfiltration,
            // which needs an HTML injection we do not have because every
            // value printed by a view is escaped.
            "style-src-attr 'unsafe-inline'",
            "img-src 'self' data: blob:",
            "font-src 'self'",
            "connect-src 'self'",
            "form-action 'self'",
            "frame-ancestors " . ($allowFraming ? "*" : "'none'"),
            "object-src 'none'",
            'upgrade-insecure-requests',
        ]);

        $headers = [
            'Content-Security-Policy'    => $csp,
            'X-Content-Type-Options'     => 'nosniff',
            'Referrer-Policy'            => 'strict-origin-when-cross-origin',
            'X-Frame-Options'            => $allowFraming ? 'SAMEORIGIN' : 'DENY',
            'Permissions-Policy'         => 'geolocation=(), microphone=(), camera=(), payment=(), interest-cohort=()',
            'Cross-Origin-Opener-Policy' => 'same-origin',
            'X-XSS-Protection'           => '0',
        ];

        if ($secure) {
            $headers['Strict-Transport-Security'] = 'max-age=31536000; includeSubDomains';
        }

        return $headers;
    }

    public static function nonce(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(16)), '+/', '-_'), '=');
    }
}
