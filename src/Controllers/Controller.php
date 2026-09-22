<?php
declare(strict_types=1);

namespace QRoute\Controllers;

use QRoute\Core\Session;
use QRoute\Core\View;
use QRoute\Http\Request;
use QRoute\Http\Response;
use QRoute\Models\User;

/**
 * Shared controller behaviour: the current user, flash messages, CSRF
 * enforcement and view rendering with the right shared data.
 */
abstract class Controller
{
    public function __construct(
        protected Request $request,
        protected Session $session
    ) {
    }

    protected function user(): ?User
    {
        static $cached = false;
        static $user = null;
        if ($cached) {
            return $user;
        }
        $cached = true;
        $id = $this->session->userId();
        $user = $id === null ? null : User::find($id);
        return $user;
    }

    /** @throws HttpRedirect when no user is signed in */
    protected function requireUser(): User
    {
        $user = $this->user();
        if ($user === null) {
            throw new HttpRedirect('/login?next=' . rawurlencode($this->request->path));
        }
        return $user;
    }

    /**
     * Verifies the CSRF token on a state-changing request.
     *
     * @throws HttpRedirect|HttpError
     */
    protected function requireCsrf(string $redirectTo = '/app'): void
    {
        if ($this->request->method !== 'POST') {
            return;
        }
        if (!$this->session->verifyCsrf()) {
            if ($this->request->isJsonRequest()) {
                throw new HttpError('Invalid or expired security token.', 419);
            }
            $this->flash('Your session expired. Please try that again.', 'warning');
            throw new HttpRedirect($redirectTo);
        }
    }

    /**
     * Cookie path for this install. A cookie set at "/" would be sent to
     * every other app on the same host, and one set at a path the app is
     * not actually served from would never come back at all.
     */
    protected function cookiePath(): string
    {
        return $this->request->basePath === '' ? '/' : $this->request->basePath . '/';
    }

    protected function flash(string $message, string $type = 'info'): void
    {
        setcookie('qr_flash', json_encode(['message' => $message, 'type' => $type]) ?: '', [
            'expires'  => time() + 60,
            'path'     => $this->cookiePath(),
            'secure'   => $this->request->secure,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }

    /** @return array{message:string,type:string}|null */
    protected function takeFlash(): ?array
    {
        $raw = $_COOKIE['qr_flash'] ?? '';
        if (!is_string($raw) || $raw === '') {
            return null;
        }
        $data = json_decode($raw, true, 4, JSON_INVALID_UTF8_SUBSTITUTE);
        setcookie('qr_flash', '', [
            'expires' => time() - 3600, 'path' => $this->cookiePath(),
            'secure' => $this->request->secure, 'httponly' => true, 'samesite' => 'Lax',
        ]);
        if (!is_array($data) || !isset($data['message'])) {
            return null;
        }
        return [
            'message' => mb_substr((string) $data['message'], 0, 300),
            'type'    => in_array($data['type'] ?? '', ['info', 'success', 'error', 'warning'], true)
                ? (string) $data['type'] : 'info',
        ];
    }

    /** @param array<string,mixed> $data */
    protected function view(string $template, array $data = []): Response
    {
        $data += [
            'currentUser' => $this->user(),
            'csrfToken'   => $this->session->csrfToken(),
            'baseUrl'     => $this->request->baseUrl(),
            'flash'       => $this->takeFlash(),
        ];
        return Response::html(View::page($template, $data));
    }

    protected function redirect(string $to, int $status = 302): Response
    {
        return Response::redirect($to, $status);
    }
}
