<?php
declare(strict_types=1);

namespace QRoute\Core;

use QRoute\Http\Request;

/**
 * Stateless double-submit CSRF tokens.
 *
 * The token is HMAC(session_id . expiry) so it needs no server storage and
 * is bound to the session that issued it: a token stolen from one user is
 * worthless to another. Tokens expire, which limits the window for a token
 * scraped from a cached page.
 */
final class Csrf
{
    public const FIELD = '_csrf';
    private const TTL = 7200;

    public static function token(string $sessionId): string
    {
        $expires = time() + self::TTL;
        $sig = substr(Security::hmac($sessionId . '|' . $expires, 'csrf'), 0, 40);
        return $expires . '.' . $sig;
    }

    public static function validate(string $sessionId, string $token): bool
    {
        if ($token === '' || !str_contains($token, '.')) {
            return false;
        }
        [$expires, $sig] = explode('.', $token, 2);
        if (!ctype_digit($expires) || (int) $expires < time()) {
            return false;
        }
        $expected = substr(Security::hmac($sessionId . '|' . (int) $expires, 'csrf'), 0, 40);
        return Security::equals($expected, $sig);
    }

    /**
     * Full verification for a state-changing request: token plus an origin
     * check, which stops the small set of attacks a double-submit token
     * alone cannot (e.g. a subdomain that can write cookies).
     */
    public static function verifyRequest(Request $request, string $sessionId): bool
    {
        $token = $request->input(self::FIELD, '') ?? '';
        if ($token === '') {
            $token = $request->header('x-csrf-token');
        }
        if (!self::validate($sessionId, $token)) {
            return false;
        }

        $origin = $request->header('origin');
        if ($origin === '') {
            $referer = $request->header('referer');
            $origin = $referer === '' ? '' : (string) preg_replace('#^([a-z]+://[^/]+).*$#i', '$1', $referer);
        }
        if ($origin === '') {
            // Some privacy tools strip both headers. The signed token is
            // still required, so we allow this rather than break those users.
            return true;
        }
        $originHost = strtolower((string) parse_url($origin, PHP_URL_HOST));
        $expectedHost = strtolower((string) parse_url($request->baseUrl(), PHP_URL_HOST));
        if ($expectedHost === '') {
            $expectedHost = strtolower($request->host);
        }
        // Compare host only; port differences are normal behind a proxy.
        return $originHost !== '' && $originHost === preg_replace('/:\d+$/', '', $expectedHost);
    }
}
