<?php
declare(strict_types=1);

namespace QRoute\Controllers;

use QRoute\Core\Config;
use QRoute\Core\Database;
use QRoute\Core\RateLimiter;
use QRoute\Core\View;
use QRoute\Http\Request;
use QRoute\Http\Response;
use QRoute\Models\Link;
use QRoute\Models\Rule;
use QRoute\Models\User;
use QRoute\Services\Analytics;
use QRoute\Services\DeviceDetector;
use QRoute\Services\GeoResolver;
use QRoute\Services\Plan;
use QRoute\Services\RuleEngine;

/**
 * The scan path.
 *
 * This is the only code in the application that a printed QR code depends
 * on, so it is written to fail open wherever it safely can: if analytics,
 * geo lookup or the rate limiter break, the visitor still reaches the
 * destination. The one thing that must never happen is a scan that goes
 * nowhere.
 */
final class RedirectController
{
    public function handle(Request $request, string $slug): Response
    {
        $link = Link::findBySlug($slug);

        if ($link === null) {
            return $this->notFound($request, $slug);
        }
        if (!$link->isActive()) {
            return $this->paused($request, $link);
        }

        $now = time();

        if ($link->hasExpired($now)) {
            $fallback = $link->expiredUrl();
            if ($fallback !== null) {
                return $this->go($fallback);
            }
            return $this->expired($request, $link);
        }

        if ($link->isPasswordProtected()) {
            $supplied = $request->input('p', '') ?? '';
            if ($supplied === '' || !$link->checkPassword($supplied)) {
                return $this->passwordPrompt($request, $link, $supplied !== '');
            }
        }

        $owner = User::find($link->userId());
        if ($owner === null) {
            return $this->notFound($request, $slug);
        }

        // Over-quota links keep redirecting; we simply stop counting. A
        // customer whose printed code stops working is a customer lost for
        // good, and an upsell prompt is worth more than an error page.
        $overQuota = false;
        try {
            $overQuota = Analytics::monthlyScanCount($owner->id(), $now) >= Plan::scansPerMonth($owner);
        } catch (\Throwable) {
            // Quota accounting must never break a redirect.
        }

        $ua = $request->userAgent();
        $device = DeviceDetector::detect($ua);

        $context = [
            'device'     => $device['device'],
            'os'         => $device['os'],
            'browser'    => $device['browser'],
            'is_bot'     => $device['is_bot'],
            'country'    => $this->safely(static fn() => GeoResolver::country($request), ''),
            'lang'       => $request->language(),
            'referer'    => $request->refererHost(),
            'now'        => $now,
            'scan_count' => $link->scanCount(),
            'ip'         => $request->ip(),
            'ua'         => $ua,
        ];

        $rules = $this->safely(static fn() => Rule::rowsForLink($link->id()), []);
        $decision = RuleEngine::resolve($rules, $context, $link->defaultUrl());
        $target = $decision['url'];
        $ruleId = $decision['rule'] === null ? null : (int) $decision['rule']['id'];

        // Bots and link previewers are recorded but never counted, so a
        // link pasted into a group chat does not inflate the numbers.
        if (!$device['is_bot'] && !$overQuota) {
            $this->safely(function () use ($link, $owner, $ruleId, $context): void {
                Analytics::record($link->id(), $owner->id(), $ruleId, $context);
                $link->recordScan($ruleId ?? 0);
            }, null);
        }

        if ($device['is_bot']) {
            // Send previewers straight through without an interstitial so
            // the chat preview shows the real destination.
            return $this->go($target);
        }

        if (Plan::showsInterstitial($owner)) {
            return $this->interstitial($request, $link, $target);
        }

        return $this->go($target);
    }

    /**
     * A 302 rather than a 301: the whole product promise is that the
     * destination can change, and a permanently cached redirect would
     * break that for anyone who scanned before the change.
     */
    private function go(string $url): Response
    {
        return (new Response('', 302, [
            'Location'      => $url,
            'Cache-Control' => 'no-store, no-cache, must-revalidate, private',
            'Referrer-Policy' => 'strict-origin-when-cross-origin',
            'X-Robots-Tag'  => 'noindex, nofollow',
        ]));
    }

    /**
     * Free-tier interstitial. This is the upgrade lever: a three second
     * branded page with an ad slot, removed entirely on a paid plan.
     */
    private function interstitial(Request $request, Link $link, string $target): Response
    {
        $delay = max(0, min(10, Config::int('INTERSTITIAL_SECONDS', 3)));

        $html = View::render('public/interstitial', [
            'target'    => $target,
            'delay'     => $delay,
            'link'      => $link,
            'baseUrl'   => $request->baseUrl(),
            'adSlot'    => Config::get('AD_SLOT_HTML', ''),
            'nonce'     => View::nonce(),
        ]);

        return Response::html($html)
            ->withHeader('Cache-Control', 'no-store, no-cache, must-revalidate, private')
            ->withHeader('X-Robots-Tag', 'noindex, nofollow')
            ->withHeader('Refresh', $delay . '; url=' . $target);
    }

    private function passwordPrompt(Request $request, Link $link, bool $failed): Response
    {
        // Rate limit guesses per link and per IP so a protected code cannot
        // be brute forced.
        $bucket = 'pw:' . $link->id() . ':' . substr(hash('sha256', $request->ip()), 0, 16);
        $limit = RateLimiter::hit($bucket, 10, 300);
        if (!$limit['allowed']) {
            return Response::html(
                View::render('public/message', [
                    'title'   => 'Too many attempts',
                    'message' => 'Please wait a few minutes and try again.',
                ]),
                429
            )->withHeader('Retry-After', (string) $limit['retry_after']);
        }

        $html = View::render('public/password', [
            'link'   => $link,
            'failed' => $failed,
            'slug'   => $link->slug(),
        ]);
        return Response::html($html, $failed ? 401 : 200)->noStore();
    }

    private function notFound(Request $request, string $slug): Response
    {
        $html = View::render('public/message', [
            'title'   => 'This code is not active',
            'message' => 'The QR code you scanned does not point anywhere yet. '
                       . 'If you own it, sign in to set its destination.',
            'cta'     => ['label' => 'Sign in to QRoute', 'href' => '/login'],
        ]);
        return Response::html($html, 404)->noStore();
    }

    private function paused(Request $request, Link $link): Response
    {
        $html = View::render('public/message', [
            'title'   => 'This code is paused',
            'message' => 'The owner has temporarily paused this QR code. Please check back later.',
        ]);
        return Response::html($html, 503)->noStore()->withHeader('Retry-After', '3600');
    }

    private function expired(Request $request, Link $link): Response
    {
        $html = View::render('public/message', [
            'title'   => 'This code has expired',
            'message' => 'This QR code was set to stop working on a given date, and that date has passed.',
        ]);
        return Response::html($html, 410)->noStore();
    }

    /** Runs a callable, swallowing failures so a redirect always completes. */
    private function safely(callable $fn, mixed $default): mixed
    {
        try {
            return $fn();
        } catch (\Throwable $e) {
            if (Config::bool('APP_DEBUG', false)) {
                error_log('[qroute] redirect path error: ' . $e->getMessage());
            }
            return $default;
        }
    }
}
