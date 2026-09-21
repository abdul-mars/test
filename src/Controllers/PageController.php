<?php
declare(strict_types=1);

namespace QRoute\Controllers;

use QRoute\Http\Response;
use QRoute\Services\QrCode;
use QRoute\Services\QrRenderer;

final class PageController extends Controller
{
    public function landing(): Response
    {
        if ($this->user() !== null) {
            return $this->redirect('/app');
        }

        // The hero code points at the site itself, so it is genuinely
        // scannable rather than a decorative picture of a QR code.
        $qr = QrCode::encode($this->request->baseUrl() . '/', QrCode::ECC_QUARTILE);

        return $this->view('public/landing', [
            'title'       => 'QRoute — dynamic QR codes you can re-point any time',
            'description' => 'Print a QR code once and change where it goes forever. '
                           . 'Route each scan by device, country, language or time of day.',
            'sampleQr'    => QrRenderer::svg($qr, ['dark' => '#11161d']),
        ]);
    }

    public function pricing(): Response
    {
        $user = $this->user();
        return $this->view('public/pricing', [
            'title'       => 'Pricing — QRoute',
            'description' => 'Free for three dynamic QR codes. Pro removes the interstitial and unlocks the API.',
            'activeNav'   => 'pricing',
            'currentPlan' => $user?->plan(),
        ]);
    }

    public function docs(): Response
    {
        return $this->view('public/docs', [
            'title'       => 'API documentation — QRoute',
            'description' => 'Create, re-point and analyse dynamic QR codes over a REST API.',
            'activeNav'   => 'docs',
        ]);
    }

    public function privacy(): Response
    {
        return $this->view('public/legal', [
            'title'     => 'Privacy — QRoute',
            'heading'   => 'Privacy policy',
            'sections'  => [
                ['What we record on a scan',
                 'When someone scans one of your codes we record the time, a coarse device '
                 . 'and browser classification, the country supplied by our CDN, the preferred '
                 . 'language and the referring domain. We do not store the visitor\'s IP address: '
                 . 'it is passed through a keyed hash that lets us recognise a repeat visit '
                 . 'within 24 hours and nothing else.'],
                ['What we never do',
                 'We do not sell scan data, we do not build cross-site advertising profiles from '
                 . 'it, and we do not set tracking cookies on the redirect path. The only cookie '
                 . 'a scan can set is none at all.'],
                ['Your account data',
                 'We store your email address and a hash of your password. Passwords are hashed '
                 . 'with Argon2id and are not recoverable by us or by anyone who obtains the '
                 . 'database.'],
                ['Retention',
                 'Raw scan events are kept for the retention period of your plan and then '
                 . 'aggregated into daily counts, after which the individual events are deleted. '
                 . 'Deleting a code deletes its scan history immediately.'],
                ['Getting your data out, or deleting it',
                 'Pro plans can export raw scans as CSV at any time. Deleting your account '
                 . 'removes your codes, rules and scan history. Codes that have been deleted stop '
                 . 'redirecting immediately.'],
            ],
        ]);
    }

    public function terms(): Response
    {
        return $this->view('public/legal', [
            'title'    => 'Terms — QRoute',
            'heading'  => 'Terms of service',
            'sections' => [
                ['Your codes keep working',
                 'If a paid plan lapses, your codes revert to free-tier behaviour rather than '
                 . 'being switched off. We will not disable a code you have already printed in '
                 . 'order to compel an upgrade.'],
                ['Acceptable use',
                 'Do not point codes at malware, phishing pages, or content that is illegal '
                 . 'where it is served. Codes used to disguise a destination for the purpose of '
                 . 'deceiving the person scanning will be removed without notice.'],
                ['Destinations are yours',
                 'You are responsible for where your codes point. We do not review destinations '
                 . 'in advance, and a destination changing after you set it is your change, not '
                 . 'ours.'],
                ['Availability',
                 'The redirect path is the part of this service that matters and is built to '
                 . 'fail open: if analytics or geo lookup are unavailable, scans still redirect. '
                 . 'We do not promise an uptime figure we cannot evidence.'],
                ['Ending the agreement',
                 'You can delete your account at any time. We may close an account that breaches '
                 . 'the acceptable use terms above.'],
            ],
        ]);
    }

    public function health(): Response
    {
        // Used by load balancers. Touches the database so that a failure
        // that only shows up under real work is still caught.
        try {
            \QRoute\Core\Database::instance()->scalar('SELECT 1');
            return Response::json(['status' => 'ok', 'time' => gmdate('c')]);
        } catch (\Throwable) {
            return Response::json(['status' => 'degraded'], 503);
        }
    }

    public function robots(): Response
    {
        // Short links and the app are kept out of search results; the
        // marketing pages are not.
        $body = "User-agent: *\n"
              . "Disallow: /app/\n"
              . "Disallow: /api/\n"
              . "Disallow: /qr/\n"
              . "Allow: /$\n"
              . "Allow: /pricing\n"
              . "Allow: /docs\n\n"
              . 'Sitemap: ' . $this->request->baseUrl() . "/sitemap.xml\n";
        return Response::text($body)->withHeader('Cache-Control', 'public, max-age=86400');
    }

    public function sitemap(): Response
    {
        $base = $this->request->baseUrl();
        $urls = ['/', '/pricing', '/docs', '/legal/privacy', '/legal/terms'];
        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
             . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
        foreach ($urls as $path) {
            $xml .= '  <url><loc>' . htmlspecialchars($base . $path, ENT_XML1) . '</loc></url>' . "\n";
        }
        $xml .= '</urlset>' . "\n";
        return Response::raw($xml, 'application/xml; charset=UTF-8')
            ->withHeader('Cache-Control', 'public, max-age=86400');
    }

    public function icon(): Response
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none">'
             . '<rect width="24" height="24" rx="5" fill="#4f46e5"/>'
             . '<rect x="4" y="4" width="6" height="6" rx="1.5" stroke="#fff" stroke-width="1.8"/>'
             . '<rect x="14" y="4" width="6" height="6" rx="1.5" stroke="#fff" stroke-width="1.8"/>'
             . '<rect x="4" y="14" width="6" height="6" rx="1.5" stroke="#fff" stroke-width="1.8"/>'
             . '<path d="M14 17h5m0 0-2-2m2 2-2 2" stroke="#fff" stroke-width="1.8" '
             . 'stroke-linecap="round" stroke-linejoin="round"/></svg>';
        return Response::raw($svg, 'image/svg+xml; charset=UTF-8')
            ->withHeader('Cache-Control', 'public, max-age=604800');
    }
}
