<?php
declare(strict_types=1);

namespace QRoute;

use QRoute\Controllers\AdminController;
use QRoute\Controllers\AnalyticsController;
use QRoute\Controllers\ApiController;
use QRoute\Controllers\AuthController;
use QRoute\Controllers\HttpError;
use QRoute\Controllers\HttpRedirect;
use QRoute\Controllers\InstallController;
use QRoute\Controllers\LinkController;
use QRoute\Controllers\PageController;
use QRoute\Controllers\QrController;
use QRoute\Controllers\RedirectController;
use QRoute\Controllers\SettingsController;
use QRoute\Core\Config;
use QRoute\Core\Security;
use QRoute\Core\Session;
use QRoute\Core\View;
use QRoute\Http\Request;
use QRoute\Http\Response;
use QRoute\Http\Router;
use QRoute\Services\Installer;

/**
 * Route table and request lifecycle.
 *
 * The short-link catch-all is registered last so that every real
 * application path wins over a slug, and Link::RESERVED keeps those paths
 * from ever being handed out as slugs in the first place.
 */
final class App
{
    public function __construct(private Request $request)
    {
    }

    public function run(): Response
    {
        $nonce = Security::nonce();
        View::setNonce($nonce);

        // Every view prints this in front of its links, so the app works
        // whether it is served from a document root of its own or from a
        // folder such as htdocs/qroute/public.
        View::share('basePath', $this->request->basePath);

        $session = new Session($this->request);
        try {
            $session->start();
        } catch (\Throwable $e) {
            // A broken session store must not take down the redirect path.
            error_log('[qroute] session start failed: ' . $e->getMessage());
        }

        // Nothing else can work before setup has run: there is no database
        // and no APP_KEY. Send every request to the wizard rather than
        // failing with a connection error nobody can act on.
        if (!Installer::isInstalled() && !str_starts_with($this->request->path, '/install')) {
            $allowed = ['/assets/app.css', '/assets/app.js', '/assets/theme-init.js', '/assets/icon.svg'];
            if (!in_array($this->request->path, $allowed, true)) {
                return $this->withSecurityHeaders(
                    Response::redirect($this->request->url('/install'))->noStore(),
                    $nonce
                );
            }
        }

        $router = $this->routes($session);

        try {
            $response = $router->dispatch($this->request);
        } catch (HttpRedirect $e) {
            $response = Response::redirect($e->to);
        } catch (HttpError $e) {
            $response = $this->errorResponse($e->getCode() ?: 400, $e->getMessage(), $session);
        } catch (\Throwable $e) {
            error_log('[qroute] ' . $e::class . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
            $detail = Config::bool('APP_DEBUG', false) ? $e->getMessage() : null;
            $response = $this->errorResponse(500, 'Something went wrong', $session, $detail);
        }

        $response = $session->applyTo($response);

        // Controllers redirect to application paths such as "/app". Adding
        // the sub-directory prefix in one place means none of them has to
        // know whether there is one.
        $location = $response->headers['Location'] ?? null;
        if (is_string($location) && str_starts_with($location, '/') && !str_starts_with($location, '//')) {
            $response->headers['Location'] = $this->request->url($location);
        }

        return $this->withSecurityHeaders($response, $nonce);
    }

    private function routes(Session $session): Router
    {
        $r = new Router();
        $req = $this->request;

        $page     = fn() => new PageController($req, $session);
        $auth     = fn() => new AuthController($req, $session);
        $links    = fn() => new LinkController($req, $session);
        $stats    = fn() => new AnalyticsController($req, $session);
        $settings = fn() => new SettingsController($req, $session);
        $qr       = fn() => new QrController($req, $session);

        // ------------------------------------------------------ install
        $install = fn() => new InstallController($req, $session);
        $r->get('/install',            fn() => $install()->show('requirements'));
        $r->get('/install/database',   fn() => $install()->show('database'));
        $r->post('/install/database',  fn() => $install()->database());
        $r->get('/install/admin',      fn() => $install()->show('admin'));
        $r->post('/install/admin',     fn() => $install()->admin());

        // ---------------------------------------------------- marketing
        $r->get('/',               fn() => $page()->landing());
        $r->get('/pricing',        fn() => $page()->pricing());
        $r->get('/docs',           fn() => $page()->docs());
        $r->get('/legal/privacy',  fn() => $page()->privacy());
        $r->get('/legal/terms',    fn() => $page()->terms());
        $r->get('/health',         fn() => $page()->health());
        $r->get('/robots.txt',     fn() => $page()->robots());
        $r->get('/sitemap.xml',    fn() => $page()->sitemap());
        $r->get('/assets/icon.svg', fn() => $page()->icon());

        // --------------------------------------------------------- auth
        $r->get('/login',     fn() => $auth()->showLogin());
        $r->post('/login',    fn() => $auth()->login());
        $r->get('/register',  fn() => $auth()->showRegister());
        $r->post('/register', fn() => $auth()->register());
        $r->any('/logout',    fn() => $auth()->logout());

        // ---------------------------------------------------------- app
        $r->get('/app',                fn() => $links()->index());
        $r->get('/app/links/new',      fn() => $links()->createForm());
        $r->post('/app/links/new',     fn() => $links()->create());
        $r->get('/app/links/{id:\d+}', fn($rq, $a) => $links()->edit($a['id']));

        $r->post('/app/links/{id:\d+}/destination', fn($rq, $a) => $links()->updateDestination($a['id']));
        $r->post('/app/links/{id:\d+}/settings',    fn($rq, $a) => $links()->updateSettings($a['id']));
        $r->post('/app/links/{id:\d+}/style',       fn($rq, $a) => $links()->updateStyle($a['id']));
        $r->post('/app/links/{id:\d+}/delete',      fn($rq, $a) => $links()->destroy($a['id']));
        $r->post('/app/links/{id:\d+}/rules',       fn($rq, $a) => $links()->addRule($a['id']));
        $r->post('/app/rules/{id:\d+}/toggle',      fn($rq, $a) => $links()->toggleRule($a['id']));
        $r->post('/app/rules/{id:\d+}/delete',      fn($rq, $a) => $links()->deleteRule($a['id']));

        $r->get('/app/analytics',                    fn() => $stats()->show(null));
        $r->get('/app/links/{id:\d+}/analytics',     fn($rq, $a) => $stats()->show($a['id']));
        $r->get('/app/links/{id:\d+}/export.csv',    fn($rq, $a) => $stats()->exportCsv($a['id']));

        $r->get('/app/settings',                   fn() => $settings()->show());
        $r->post('/app/settings/profile',          fn() => $settings()->updateProfile());
        $r->post('/app/settings/password',         fn() => $settings()->updatePassword());
        $r->post('/app/settings/keys',             fn() => $settings()->createKey());
        $r->post('/app/settings/keys/{id:\d+}/revoke', fn($rq, $a) => $settings()->revokeKey($a['id']));
        $r->any('/app/billing',                    fn() => $settings()->billing());

        // -------------------------------------------------------- admin
        $adminC = fn() => new AdminController($req, $session);
        $r->get('/admin',                      fn() => $adminC()->dashboard());
        $r->get('/admin/users',                fn() => $adminC()->users());
        $r->post('/admin/users/{id:\d+}',      fn($rq, $a) => $adminC()->updateUser($a['id']));
        $r->get('/admin/links',                fn() => $adminC()->links());
        $r->post('/admin/links/{id:\d+}',      fn($rq, $a) => $adminC()->updateLink($a['id']));

        // ---------------------------------------------------- QR images
        $r->get('/qr/{slug:[A-Za-z0-9_-]{3,32}}.{format:svg|png}',
            fn($rq, $a) => $qr()->render($a['slug'], $a['format']));

        // ---------------------------------------------------------- API
        $api = static fn(string $action) => static function (Request $rq, array $a = []) use ($action): Response {
            return (new ApiController($rq))->handle($action, $a);
        };
        $r->get('/api/v1/me',                      $api('me'));
        $r->get('/api/v1/links',                   $api('listLinks'));
        $r->post('/api/v1/links',                  $api('createLink'));
        $r->get('/api/v1/links/{id:\d+}',          $api('showLink'));
        $r->post('/api/v1/links/{id:\d+}',         $api('updateLink'));
        $r->post('/api/v1/links/{id:\d+}/delete',  $api('deleteLink'));
        $r->post('/api/v1/links/{id:\d+}/rules',   $api('createRule'));
        $r->post('/api/v1/rules/{id:\d+}/delete',  $api('deleteRule'));
        $r->get('/api/v1/links/{id:\d+}/stats',    $api('stats'));

        // --------------------------------------------- short link (last)
        $redirect = new RedirectController();
        $r->any('/{slug:[A-Za-z0-9_-]{3,32}}', fn($rq, $a) => $redirect->handle($rq, $a['slug']));

        $r->fallback(function (Request $rq) use ($session): Response {
            return $this->errorResponse(404, 'Page not found', $session);
        });

        return $r;
    }

    private function errorResponse(int $status, string $message, Session $session, ?string $detail = null): Response
    {
        $status = $status >= 400 && $status < 600 ? $status : 500;

        if ($this->request->isJsonRequest() || str_starts_with($this->request->path, '/api/')) {
            return Response::json(['ok' => false, 'error' => $message], $status);
        }

        try {
            $html = View::page('errors/error', [
                'title'       => $message . ' — QRoute',
                'status'      => $status,
                'message'     => $message,
                'detail'      => $detail,
                'currentUser' => null,
                'csrfToken'   => $session->csrfToken(),
                'baseUrl'     => $this->request->baseUrl(),
            ]);
            return Response::html($html, $status);
        } catch (\Throwable) {
            return Response::text($message, $status);
        }
    }

    private function withSecurityHeaders(Response $response, string $nonce): Response
    {
        $type = $response->headers['Content-Type'] ?? '';

        // Image and JSON responses carry their own narrower policy; the
        // full HTML header set only makes sense on an HTML document.
        if (!str_contains($type, 'text/html')) {
            $response->headers['X-Content-Type-Options'] ??= 'nosniff';
            if ($this->request->secure) {
                $response->headers['Strict-Transport-Security'] ??= 'max-age=31536000; includeSubDomains';
            }
            return $response;
        }

        foreach (Security::headers($nonce, $this->request->secure) as $name => $value) {
            $response->headers[$name] ??= $value;
        }
        return $response;
    }
}
