<?php
declare(strict_types=1);

namespace QRoute\Core;

use QRoute\Http\Request;
use QRoute\Http\Response;

/**
 * Database-backed sessions.
 *
 * PHP's native handler is avoided on purpose: server-side rows let us list
 * and revoke a user's active devices, survive a horizontally scaled
 * deployment with no sticky sessions, and bind a session to the
 * fingerprint it was created with.
 */
final class Session
{
    public const COOKIE = 'qr_session';
    private const LIFETIME = 60 * 60 * 24 * 14;
    private const ROTATE_AFTER = 60 * 60 * 24;

    private ?string $id = null;
    private ?int $userId = null;
    /** @var array<string,mixed> */
    private array $flash = [];
    private bool $dirtyCookie = false;
    private ?string $pendingCookieValue = null;
    private bool $clearCookie = false;
    private bool $cookieApplied = false;

    public function __construct(private Request $request)
    {
    }

    public function start(): void
    {
        $raw = $_COOKIE[self::COOKIE] ?? '';
        if (!is_string($raw) || $raw === '') {
            return;
        }
        if (!str_contains($raw, '.')) {
            return;
        }
        [$id, $sig] = explode('.', $raw, 2);
        if (!preg_match('/^[A-Za-z0-9_-]{16,64}$/', $id)) {
            return;
        }
        if (!Security::equals(substr(Security::hmac($id, 'session'), 0, 32), $sig)) {
            return;
        }

        $row = Database::instance()->first(
            'SELECT * FROM sessions WHERE id = :id',
            ['id' => $id]
        );
        if ($row === null) {
            return;
        }
        if ((int) $row['expires_at'] < time()) {
            Database::instance()->run('DELETE FROM sessions WHERE id = :id', ['id' => $id]);
            return;
        }

        // A session cookie replayed from a different browser is rejected.
        $uaHash = substr(Security::hmac($this->request->userAgent(), 'ua'), 0, 32);
        if ($row['ua_hash'] !== '' && !Security::equals((string) $row['ua_hash'], $uaHash)) {
            Database::instance()->run('DELETE FROM sessions WHERE id = :id', ['id' => $id]);
            return;
        }

        $this->id = $id;
        $this->userId = (int) $row['user_id'];

        $lastSeen = (int) $row['last_seen_at'];
        if (time() - $lastSeen > 300) {
            Database::instance()->update(
                'sessions',
                ['last_seen_at' => time(), 'expires_at' => time() + self::LIFETIME],
                ['id' => $id]
            );
        }
        // Periodic rotation limits the value of a stolen cookie.
        if (time() - (int) $row['created_at'] > self::ROTATE_AFTER) {
            $this->rotate();
        }
    }

    public function login(int $userId): void
    {
        $this->destroy();
        $this->id = Security::randomToken(24);
        $this->userId = $userId;

        Database::instance()->insert('sessions', [
            'id'           => $this->id,
            'user_id'      => $userId,
            'ip_hash'      => substr(Security::hmac($this->request->ip(), 'ip'), 0, 32),
            'ua_hash'      => substr(Security::hmac($this->request->userAgent(), 'ua'), 0, 32),
            'created_at'   => time(),
            'last_seen_at' => time(),
            'expires_at'   => time() + self::LIFETIME,
        ]);

        $this->queueCookie();
    }

    /** Issues a new session id for the same user, preserving the login. */
    public function rotate(): void
    {
        if ($this->id === null || $this->userId === null) {
            return;
        }
        $old = $this->id;
        $new = Security::randomToken(24);
        $db = Database::instance();
        $db->run(
            'UPDATE sessions SET id = :new, created_at = :now, last_seen_at = :now WHERE id = :old',
            ['new' => $new, 'old' => $old, 'now' => time()]
        );
        $this->id = $new;
        $this->queueCookie();
    }

    public function destroy(): void
    {
        if ($this->id !== null) {
            Database::instance()->run('DELETE FROM sessions WHERE id = :id', ['id' => $this->id]);
        }
        $this->id = null;
        $this->userId = null;
        $this->clearCookie = true;
        $this->dirtyCookie = true;
        $this->cookieApplied = false;
    }

    public function userId(): ?int
    {
        return $this->userId;
    }

    /** A stable id even when logged out, so CSRF works on the login form. */
    public function csrfId(): string
    {
        if ($this->id !== null) {
            return $this->id;
        }
        // Anonymous visitors get a token bound to their fingerprint. It is
        // not a session, just enough entropy to bind the form.
        return substr(Security::hmac($this->request->ip() . '|' . $this->request->userAgent(), 'anon'), 0, 32);
    }

    public function csrfToken(): string
    {
        return Csrf::token($this->csrfId());
    }

    public function verifyCsrf(): bool
    {
        return Csrf::verifyRequest($this->request, $this->csrfId());
    }

    /**
     * Attaches the session cookie to a response.
     *
     * Controllers that log a user in call this themselves so the cookie
     * rides the redirect, and the kernel calls it again for every other
     * response. It is idempotent so the pair does not emit the header
     * twice.
     */
    public function applyTo(Response $response): Response
    {
        if (!$this->dirtyCookie || $this->cookieApplied) {
            return $response;
        }
        $this->cookieApplied = true;
        $options = [
            'expires'  => $this->clearCookie ? time() - 3600 : time() + self::LIFETIME,
            // Scoped to the sub-directory the app is served from, so two
            // apps under the same host do not overwrite each other's
            // session, and so the cookie is actually sent back to us.
            'path'     => $this->request->basePath === '' ? '/' : $this->request->basePath . '/',
            'domain'   => '',
            'secure'   => $this->request->secure,
            'httponly' => true,
            'samesite' => 'Lax',
        ];
        return $response->withCookie(
            self::COOKIE,
            $this->clearCookie ? '' : (string) $this->pendingCookieValue,
            $options
        );
    }

    private function queueCookie(): void
    {
        $id = (string) $this->id;
        $this->pendingCookieValue = $id . '.' . substr(Security::hmac($id, 'session'), 0, 32);
        $this->clearCookie = false;
        $this->dirtyCookie = true;
        $this->cookieApplied = false;
    }

    /** Removes expired rows. Called by the GC command. */
    public static function prune(): int
    {
        return Database::instance()
            ->run('DELETE FROM sessions WHERE expires_at < :t', ['t' => time()])
            ->rowCount();
    }
}
