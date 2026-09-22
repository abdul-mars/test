<?php
declare(strict_types=1);

namespace QRoute\Controllers;

use QRoute\Core\Config;
use QRoute\Core\Database;
use QRoute\Core\Security;
use QRoute\Core\View;
use QRoute\Http\Response;
use QRoute\Models\User;
use QRoute\Services\Installer;

/**
 * The first-run wizard.
 *
 * Four steps: check the environment, connect the database, create the
 * administrator, done. Each step re-checks what came before, so someone
 * who bookmarks step three or refreshes mid-way is sent back rather than
 * ending up with a half-built install.
 *
 * Every action refuses to run once the lock file exists.
 */
final class InstallController extends Controller
{
    private const STEPS = ['requirements', 'database', 'admin', 'done'];

    public function show(string $step = 'requirements'): Response
    {
        if ($guard = $this->guardInstalled()) {
            return $guard;
        }
        if (!in_array($step, self::STEPS, true)) {
            $step = 'requirements';
        }

        $checks = Installer::requirements();

        // Do not let someone skip ahead past a failing environment.
        if ($step !== 'requirements' && !Installer::requirementsMet($checks)) {
            return $this->redirect('/install');
        }

        return $this->installView($step, [
            'checks' => $checks,
            'ready'  => Installer::requirementsMet($checks),
            'config' => $this->rememberedConfig(),
        ]);
    }

    /** Step 2: verify (and optionally create) the database, then migrate. */
    public function database(): Response
    {
        if ($guard = $this->guardInstalled()) {
            return $guard;
        }
        if (!$this->session->verifyCsrf()) {
            return $this->redirect('/install/database');
        }

        $config = [
            'DB_DRIVER' => $this->request->input('db_driver', 'mysql') === 'sqlite' ? 'sqlite' : 'mysql',
            'DB_HOST'   => (string) $this->request->input('db_host', '127.0.0.1'),
            'DB_PORT'   => (string) $this->request->input('db_port', '3306'),
            'DB_NAME'   => (string) $this->request->input('db_name', 'qroute'),
            'DB_USER'   => (string) $this->request->input('db_user', 'root'),
            'DB_PASS'   => (string) ($this->request->post['db_pass'] ?? ''),
            'DB_PATH'   => (string) $this->request->input('db_path', 'storage/qroute.sqlite'),
        ];

        $test = Installer::testDatabase($config, $this->request->boolean('create_db'));
        if (!$test['ok']) {
            return $this->installView('database', [
                'checks' => Installer::requirements(),
                'ready'  => true,
                'config' => $config,
                'error'  => $test['error'],
            ]);
        }

        $migration = Installer::migrate($config);
        if (!$migration['ok']) {
            return $this->installView('database', [
                'checks' => Installer::requirements(),
                'ready'  => true,
                'config' => $config,
                'error'  => 'Connected, but the tables could not be created: ' . $migration['error'],
            ]);
        }

        // Carry the settings to the next step in a short-lived signed cookie
        // rather than a session, which does not exist yet.
        $response = $this->redirect('/install/admin');
        return $this->stashConfig($response, $config, [
            'server'  => $test['server'],
            'created' => $test['created'],
            'applied' => $migration['applied'],
        ]);
    }

    /** Step 3: create the administrator and write the configuration. */
    public function admin(): Response
    {
        if ($guard = $this->guardInstalled()) {
            return $guard;
        }
        if (!$this->session->verifyCsrf()) {
            return $this->redirect('/install/admin');
        }

        $config = $this->rememberedConfig();
        if (($config['DB_DRIVER'] ?? '') === '') {
            return $this->redirect('/install/database');
        }

        foreach ($config as $key => $value) {
            Config::set($key, (string) $value);
        }
        Database::reset();

        $email    = (string) $this->request->input('email', '');
        $password = (string) ($this->request->post['password'] ?? '');
        $confirm  = (string) ($this->request->post['password_confirm'] ?? '');
        $name     = (string) $this->request->input('display_name', '');
        $appUrl   = rtrim((string) $this->request->input('app_url', ''), '/');

        $errors = [];
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Enter a valid email address — this is the account you will sign in with.';
        }
        if ($password !== $confirm) {
            $errors[] = 'The two passwords do not match.';
        }
        $pwProblem = User::passwordProblem($password);
        if ($pwProblem !== null) {
            $errors[] = $pwProblem;
        }
        if ($appUrl === '' || !preg_match('#^https?://#i', $appUrl)) {
            $errors[] = 'The site address must start with http:// or https://';
        }

        if ($errors !== []) {
            return $this->installView('admin', [
                'checks' => Installer::requirements(),
                'ready'  => true,
                'config' => $config,
                'errors' => $errors,
                'values' => ['email' => $email, 'display_name' => $name, 'app_url' => $appUrl],
            ]);
        }

        // The key must exist before the admin's password is hashed, so that
        // sessions created later validate against the same secret.
        $appKey = Security::generateKey();

        $envValues = array_merge($config, [
            'APP_KEY'   => $appKey,
            'APP_URL'   => $appUrl,
            'APP_DEBUG' => 'false',
        ]);
        if ($config['DB_DRIVER'] === 'mysql') {
            unset($envValues['DB_PATH']);
        }

        $env = Installer::writeEnv($envValues);

        $created = Installer::createAdmin($email, $password, $name);
        if (!$created['ok'] || $created['user'] === null) {
            return $this->installView('admin', [
                'checks' => Installer::requirements(),
                'ready'  => true,
                'config' => $config,
                'errors' => [$created['error']],
                'values' => ['email' => $email, 'display_name' => $name, 'app_url' => $appUrl],
            ]);
        }

        Installer::finalise($email);

        $response = $this->installView('done', [
            'checks'      => Installer::requirements(),
            'ready'       => true,
            'config'      => $config,
            'adminEmail'  => $email,
            'appUrl'      => $appUrl,
            'envWritten'  => $env['ok'],
            'envContents' => $env['ok'] ? '' : $env['contents'],
            'appKey'      => $appKey,
        ]);

        return $this->forgetConfig($response);
    }

    // -------------------------------------------------------- helpers

    /**
     * Refuses every installer route once setup has completed. Without this
     * an installer left in place is a way for anyone to point a live site
     * at a database they control.
     */
    private function guardInstalled(): ?Response
    {
        if (!Installer::isInstalled()) {
            return null;
        }
        return Response::html(
            View::render('install/locked', ['lockFile' => Installer::LOCK_FILE]),
            410
        )->noStore();
    }

    /** @param array<string,mixed> $data */
    private function installView(string $step, array $data): Response
    {
        $html = View::render('install/layout', array_merge($data, [
            'step'      => $step,
            'steps'     => self::STEPS,
            'csrfToken' => $this->session->csrfToken(),
            'baseUrl'   => $this->request->baseUrl(),
            'guessedUrl' => ($this->request->secure ? 'https://' : 'http://') . $this->request->host,
        ]));
        return Response::html($html)->noStore();
    }

    /** @return array<string,string> */
    private function rememberedConfig(): array
    {
        $raw = $_COOKIE['qr_install'] ?? '';
        if (!is_string($raw) || !str_contains($raw, '.')) {
            return [];
        }
        [$payload, $sig] = explode('.', $raw, 2);
        if (!Security::equals(substr(Security::hmac($payload, 'install'), 0, 32), $sig)) {
            return [];
        }
        $decoded = json_decode((string) base64_decode(strtr($payload, '-_', '+/'), true), true);
        if (!is_array($decoded)) {
            return [];
        }
        $out = [];
        foreach ($decoded as $k => $v) {
            if (is_string($k) && (is_string($v) || is_int($v))) {
                $out[$k] = (string) $v;
            }
        }
        return $out;
    }

    /** @param array<string,string> $config */
    private function stashConfig(Response $response, array $config, array $meta = []): Response
    {
        $payload = rtrim(strtr(base64_encode((string) json_encode($config)), '+/', '-_'), '=');
        $value = $payload . '.' . substr(Security::hmac($payload, 'install'), 0, 32);

        return $response->withCookie('qr_install', $value, [
            'expires'  => time() + 1800,
            'path'     => $this->request->url('/install'),
            'secure'   => $this->request->secure,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }

    private function forgetConfig(Response $response): Response
    {
        return $response->withCookie('qr_install', '', [
            'expires'  => time() - 3600,
            'path'     => $this->request->url('/install'),
            'secure'   => $this->request->secure,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }
}
