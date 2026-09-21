<?php
declare(strict_types=1);

namespace QRoute\Services;

/**
 * User-agent classification for routing and analytics.
 *
 * Deliberately coarse. Routing decisions only ever need "is this a phone,
 * and which app store should it go to", and a small ordered rule set is
 * both faster and far easier to reason about than a giant UA database
 * that goes stale.
 */
final class DeviceDetector
{
    public const DEVICES = ['mobile', 'tablet', 'desktop', 'bot', 'unknown'];
    public const OSES    = ['ios', 'android', 'windows', 'macos', 'linux', 'chromeos', 'other'];

    /**
     * @return array{device:string,os:string,browser:string,is_bot:bool}
     */
    public static function detect(string $ua): array
    {
        $ua = trim($ua);
        if ($ua === '') {
            return ['device' => 'unknown', 'os' => 'other', 'browser' => 'unknown', 'is_bot' => false];
        }
        $l = strtolower($ua);

        if (self::looksLikeBot($l)) {
            return ['device' => 'bot', 'os' => 'other', 'browser' => 'bot', 'is_bot' => true];
        }

        $os = self::os($l);
        $device = self::device($l, $os);
        $browser = self::browser($l);

        return ['device' => $device, 'os' => $os, 'browser' => $browser, 'is_bot' => false];
    }

    private static function looksLikeBot(string $l): bool
    {
        // Link previewers matter as much as crawlers here: when someone
        // pastes a short link into a chat app, the preview fetch must not
        // be counted as a scan or it inflates every customer's numbers.
        static $needles = [
            'bot', 'crawler', 'spider', 'slurp', 'curl/', 'wget/', 'python-requests',
            'go-http-client', 'java/', 'okhttp', 'axios/', 'headlesschrome', 'phantomjs',
            'facebookexternalhit', 'whatsapp', 'telegrambot', 'slackbot', 'discordbot',
            'twitterbot', 'linkedinbot', 'embedly', 'quora link preview', 'pinterest',
            'redditbot', 'applebot', 'bingpreview', 'skypeuripreview', 'vkshare',
            'googleother', 'gptbot', 'claudebot', 'perplexitybot', 'ccbot',
            'uptime', 'pingdom', 'statuscake', 'monitoring', 'preview', 'lighthouse',
        ];
        foreach ($needles as $needle) {
            if (str_contains($l, $needle)) {
                return true;
            }
        }
        return false;
    }

    private static function os(string $l): string
    {
        // Order matters: iPadOS reports "Macintosh" in desktop mode, and
        // Android UAs also contain "linux".
        if (str_contains($l, 'iphone') || str_contains($l, 'ipad') || str_contains($l, 'ipod')) {
            return 'ios';
        }
        if (str_contains($l, 'android')) {
            return 'android';
        }
        if (str_contains($l, 'cros')) {
            return 'chromeos';
        }
        if (str_contains($l, 'windows')) {
            return 'windows';
        }
        if (str_contains($l, 'mac os x') || str_contains($l, 'macintosh')) {
            // An iPad in "Request Desktop Site" mode is still an iPad; the
            // touch hint is the only reliable tell.
            if (str_contains($l, 'mobile/') || str_contains($l, 'touch')) {
                return 'ios';
            }
            return 'macos';
        }
        if (str_contains($l, 'linux') || str_contains($l, 'x11')) {
            return 'linux';
        }
        return 'other';
    }

    private static function device(string $l, string $os): string
    {
        if (str_contains($l, 'ipad') || str_contains($l, 'tablet') || str_contains($l, 'kindle')
            || str_contains($l, 'playbook') || str_contains($l, 'silk')) {
            return 'tablet';
        }
        // Android without "mobile" is, by Google's own convention, a tablet.
        if ($os === 'android') {
            return str_contains($l, 'mobile') ? 'mobile' : 'tablet';
        }
        if ($os === 'ios') {
            return str_contains($l, 'ipad') ? 'tablet' : 'mobile';
        }
        if (str_contains($l, 'mobile') || str_contains($l, 'phone')) {
            return 'mobile';
        }
        if (in_array($os, ['windows', 'macos', 'linux', 'chromeos'], true)) {
            return 'desktop';
        }
        return 'unknown';
    }

    private static function browser(string $l): string
    {
        // Every Chromium browser claims to be Chrome and Safari, so the
        // more specific tokens have to be tested first.
        return match (true) {
            str_contains($l, 'edg/') || str_contains($l, 'edga/') => 'edge',
            str_contains($l, 'opr/') || str_contains($l, 'opera')  => 'opera',
            str_contains($l, 'samsungbrowser')                     => 'samsung',
            str_contains($l, 'firefox') || str_contains($l, 'fxios') => 'firefox',
            str_contains($l, 'crios') || str_contains($l, 'chrome') => 'chrome',
            str_contains($l, 'safari')                             => 'safari',
            default                                                => 'other',
        };
    }
}
