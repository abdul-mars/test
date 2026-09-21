<?php
declare(strict_types=1);

namespace QRoute\Services;

use QRoute\Models\User;

/**
 * Plan definitions and quota enforcement.
 *
 * The free tier is deliberately generous on *creating* codes and stingy on
 * *volume and polish*: a printed QR code is worthless if it stops working,
 * so limits are applied to branding, analytics depth and routing rules
 * rather than ever hard-failing a scan. A scan that 404s is a customer
 * who reprints their menu with a competitor's code on it.
 */
final class Plan
{
    /** @var array<string,array<string,mixed>> */
    public const PLANS = [
        User::PLAN_FREE => [
            'name'            => 'Free',
            'price_monthly'   => 0,
            'price_yearly'    => 0,
            'max_links'       => 3,
            'max_rules'       => 2,
            'scans_per_month' => 1000,
            'history_days'    => 30,
            'interstitial'    => true,
            'custom_colors'   => false,
            'logo_upload'     => false,
            'api_access'      => false,
            'custom_schemes'  => false,
            'csv_export'      => false,
            'password_links'  => false,
            'blurb'           => 'Everything you need to put a working, editable QR code on something.',
            'features'        => [
                '3 dynamic QR codes',
                'Change the destination any time, forever',
                '2 routing rules per code',
                '1,000 scans/month',
                '30 days of analytics',
                'SVG + PNG download',
            ],
        ],
        User::PLAN_PRO => [
            'name'            => 'Pro',
            'price_monthly'   => 12,
            'price_yearly'    => 108,
            'max_links'       => 100,
            'max_rules'       => 25,
            'scans_per_month' => 250000,
            'history_days'    => 730,
            'interstitial'    => false,
            'custom_colors'   => true,
            'logo_upload'     => true,
            'api_access'      => true,
            'custom_schemes'  => true,
            'csv_export'      => true,
            'password_links'  => true,
            'blurb'           => 'For anyone whose codes are in print and whose time is worth more than $12.',
            'features'        => [
                '100 dynamic QR codes',
                'No QRoute interstitial — instant redirect',
                '25 routing rules per code',
                '250,000 scans/month',
                '2 years of analytics + CSV export',
                'Brand colours and logo in the code',
                'App deep links (myapp://)',
                'Password-protected codes',
                'REST API + API keys',
            ],
        ],
        User::PLAN_TEAM => [
            'name'            => 'Team',
            'price_monthly'   => 49,
            'price_yearly'    => 441,
            'max_links'       => 2000,
            'max_rules'       => 100,
            'scans_per_month' => 5000000,
            'history_days'    => 1825,
            'interstitial'    => false,
            'custom_colors'   => true,
            'logo_upload'     => true,
            'api_access'      => true,
            'custom_schemes'  => true,
            'csv_export'      => true,
            'password_links'  => true,
            'blurb'           => 'Agencies and multi-location brands running codes at scale.',
            'features'        => [
                'Everything in Pro',
                '2,000 dynamic QR codes',
                '5,000,000 scans/month',
                '5 years of analytics',
                'Bulk create and bulk retarget via API',
                'Priority support',
            ],
        ],
    ];

    /** @return array<string,mixed> */
    public static function for(User $user): array
    {
        return self::PLANS[$user->plan()] ?? self::PLANS[User::PLAN_FREE];
    }

    public static function limit(User $user, string $key): mixed
    {
        return self::for($user)[$key] ?? null;
    }

    public static function can(User $user, string $feature): bool
    {
        return (bool) (self::for($user)[$feature] ?? false);
    }

    public static function maxLinks(User $user): int
    {
        return (int) self::limit($user, 'max_links');
    }

    public static function maxRules(User $user): int
    {
        return (int) self::limit($user, 'max_rules');
    }

    public static function scansPerMonth(User $user): int
    {
        return (int) self::limit($user, 'scans_per_month');
    }

    public static function historyDays(User $user): int
    {
        return (int) self::limit($user, 'history_days');
    }

    /** True when the free-tier interstitial should be shown before redirect. */
    public static function showsInterstitial(User $user): bool
    {
        return (bool) self::limit($user, 'interstitial');
    }

    public static function displayName(string $plan): string
    {
        return (string) (self::PLANS[$plan]['name'] ?? 'Free');
    }
}
