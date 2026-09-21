<?php
declare(strict_types=1);

namespace QRoute\Controllers;

use QRoute\Core\Database;
use QRoute\Core\RateLimiter;
use QRoute\Core\Security;
use QRoute\Http\Response;
use QRoute\Models\User;

final class AuthController extends Controller
{
    public function showLogin(): Response
    {
        if ($this->user() !== null) {
            return $this->redirect('/app');
        }
        return $this->view('auth/login', ['title' => 'Sign in — QRoute', 'activeNav' => '']);
    }

    public function login(): Response
    {
        if ($this->user() !== null) {
            return $this->redirect('/app');
        }
        $this->requireCsrf('/login');

        $email = (string) $this->request->input('email', '');
        $password = (string) $this->request->input('password', '');

        // Two buckets: one per address so a single account cannot be ground
        // down, one per IP so a botnet cannot spray many accounts at once.
        $ipBucket = 'login:ip:' . substr(hash('sha256', $this->request->ip()), 0, 16);
        $idBucket = 'login:id:' . substr(hash('sha256', User::normalizeEmail($email)), 0, 16);

        $ipLimit = RateLimiter::hit($ipBucket, 30, 900);
        $idLimit = RateLimiter::hit($idBucket, 10, 900);

        if (!$ipLimit['allowed'] || !$idLimit['allowed']) {
            return $this->view('auth/login', [
                'title' => 'Sign in — QRoute',
                'email' => $email,
                'error' => 'Too many sign-in attempts. Please wait a few minutes and try again.',
            ])->withHeader('Retry-After', (string) max($ipLimit['retry_after'], $idLimit['retry_after']));
        }

        $user = User::attemptLogin($email, $password);
        if ($user === null) {
            $this->audit(null, 'login.failed', $email);
            return $this->view('auth/login', [
                'title' => 'Sign in — QRoute',
                'email' => $email,
                'error' => 'That email and password do not match an account.',
            ]);
        }

        $this->session->login($user->id());
        $this->audit($user->id(), 'login.success', $email);

        $next = (string) $this->request->input('next', '/app');
        return $this->session->applyTo($this->redirect($this->safeNext($next)));
    }

    public function showRegister(): Response
    {
        if ($this->user() !== null) {
            return $this->redirect('/app');
        }
        return $this->view('auth/register', ['title' => 'Create your account — QRoute']);
    }

    public function register(): Response
    {
        if ($this->user() !== null) {
            return $this->redirect('/app');
        }
        $this->requireCsrf('/register');

        $bucket = 'register:' . substr(hash('sha256', $this->request->ip()), 0, 16);
        $limit = RateLimiter::hit($bucket, 5, 3600);
        if (!$limit['allowed']) {
            return $this->view('auth/register', [
                'title' => 'Create your account — QRoute',
                'error' => 'Too many accounts created from this network. Please try again later.',
            ])->withHeader('Retry-After', (string) $limit['retry_after']);
        }

        $email = (string) $this->request->input('email', '');
        $password = (string) $this->request->input('password', '');

        $result = User::register($email, $password);
        if (!$result['ok'] || $result['user'] === null) {
            return $this->view('auth/register', [
                'title' => 'Create your account — QRoute',
                'email' => $email,
                'error' => $result['error'],
            ]);
        }

        $this->session->login($result['user']->id());
        $this->audit($result['user']->id(), 'user.registered', $email);
        $this->flash('Welcome to QRoute. Create your first code below.', 'success');

        return $this->session->applyTo($this->redirect('/app/links/new'));
    }

    public function logout(): Response
    {
        if ($this->request->method === 'POST' && !$this->session->verifyCsrf()) {
            // A forged logout is only a nuisance, but there is no reason to
            // honour one.
            return $this->redirect('/app');
        }
        $userId = $this->session->userId();
        $this->session->destroy();
        if ($userId !== null) {
            $this->audit($userId, 'logout', '');
        }
        return $this->session->applyTo($this->redirect('/'));
    }

    /** Keeps an open redirect out of the ?next= parameter. */
    private function safeNext(string $next): string
    {
        if ($next === '' || !str_starts_with($next, '/') || str_starts_with($next, '//')) {
            return '/app';
        }
        return $next;
    }

    private function audit(?int $userId, string $action, string $subject): void
    {
        try {
            Database::instance()->insert('audit_log', [
                'user_id'    => $userId,
                'action'     => $action,
                'subject'    => mb_substr($subject, 0, 191),
                'meta'       => null,
                'ip_hash'    => substr(Security::hmac($this->request->ip(), 'ip'), 0, 32),
                'created_at' => time(),
            ]);
        } catch (\Throwable) {
            // Auditing is best effort; never block a sign-in over it.
        }
    }
}
