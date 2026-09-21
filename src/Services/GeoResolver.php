<?php
declare(strict_types=1);

namespace QRoute\Services;

use QRoute\Core\Config;
use QRoute\Http\Request;

/**
 * Country lookup for geo routing.
 *
 * Resolution order:
 *   1. A CDN-supplied header (Cloudflare, Fastly, CloudFront, Vercel). This
 *      is free, instant, and accurate, so it is preferred.
 *   2. An optional local MaxMind GeoLite2 database, if one is configured.
 *   3. Unknown.
 *
 * Headers are only trusted when the request came from a trusted proxy,
 * otherwise a visitor could set CF-IPCountry themselves and steer their
 * own routing.
 */
final class GeoResolver
{
    private const HEADERS = [
        'cf-ipcountry',          // Cloudflare
        'x-vercel-ip-country',   // Vercel
        'cloudfront-viewer-country',
        'x-geo-country',         // Fastly / custom
        'x-country-code',
    ];

    private static ?\MaxMind\Db\Reader $reader = null;
    private static bool $readerChecked = false;

    /** Returns an ISO 3166-1 alpha-2 code, or '' when unknown. */
    public static function country(Request $request): string
    {
        if (self::proxyHeadersTrusted()) {
            foreach (self::HEADERS as $header) {
                $value = strtoupper(trim($request->header($header)));
                if (preg_match('/^[A-Z]{2}$/', $value) === 1 && $value !== 'XX' && $value !== 'T1') {
                    return $value;
                }
            }
        }

        $reader = self::reader();
        if ($reader !== null) {
            try {
                $record = $reader->get($request->ip());
                $code = $record['country']['iso_code'] ?? $record['registered_country']['iso_code'] ?? null;
                if (is_string($code) && preg_match('/^[A-Z]{2}$/', $code) === 1) {
                    return $code;
                }
            } catch (\Throwable) {
                // An unreadable database must never break a redirect.
            }
        }

        return '';
    }

    private static function proxyHeadersTrusted(): bool
    {
        $trusted = trim((string) Config::get('TRUSTED_PROXIES', ''));
        return $trusted !== '';
    }

    private static function reader(): ?\MaxMind\Db\Reader
    {
        if (self::$readerChecked) {
            return self::$reader;
        }
        self::$readerChecked = true;

        $path = Config::get('GEOIP_DB', '');
        if ($path === null || $path === '' || !is_readable($path) || !class_exists(\MaxMind\Db\Reader::class)) {
            return self::$reader = null;
        }
        try {
            return self::$reader = new \MaxMind\Db\Reader($path);
        } catch (\Throwable) {
            return self::$reader = null;
        }
    }

    /** @return array<string,string> ISO code => display name, for the rule builder UI. */
    public static function countryList(): array
    {
        static $list = null;
        if ($list !== null) {
            return $list;
        }
        $codes = [
            'US' => 'United States', 'GB' => 'United Kingdom', 'CA' => 'Canada',
            'AU' => 'Australia', 'NZ' => 'New Zealand', 'IE' => 'Ireland',
            'DE' => 'Germany', 'FR' => 'France', 'ES' => 'Spain', 'IT' => 'Italy',
            'PT' => 'Portugal', 'NL' => 'Netherlands', 'BE' => 'Belgium',
            'CH' => 'Switzerland', 'AT' => 'Austria', 'SE' => 'Sweden',
            'NO' => 'Norway', 'DK' => 'Denmark', 'FI' => 'Finland', 'PL' => 'Poland',
            'CZ' => 'Czechia', 'GR' => 'Greece', 'TR' => 'Turkey', 'RU' => 'Russia',
            'UA' => 'Ukraine', 'RO' => 'Romania', 'HU' => 'Hungary',
            'AE' => 'United Arab Emirates', 'SA' => 'Saudi Arabia', 'QA' => 'Qatar',
            'IL' => 'Israel', 'EG' => 'Egypt', 'ZA' => 'South Africa',
            'NG' => 'Nigeria', 'KE' => 'Kenya', 'GH' => 'Ghana', 'MA' => 'Morocco',
            'IN' => 'India', 'PK' => 'Pakistan', 'BD' => 'Bangladesh',
            'CN' => 'China', 'JP' => 'Japan', 'KR' => 'South Korea',
            'SG' => 'Singapore', 'MY' => 'Malaysia', 'ID' => 'Indonesia',
            'TH' => 'Thailand', 'VN' => 'Vietnam', 'PH' => 'Philippines',
            'HK' => 'Hong Kong', 'TW' => 'Taiwan',
            'BR' => 'Brazil', 'MX' => 'Mexico', 'AR' => 'Argentina',
            'CL' => 'Chile', 'CO' => 'Colombia', 'PE' => 'Peru',
        ];
        asort($codes);
        return $list = $codes;
    }
}
