<?php
declare(strict_types=1);

namespace QRoute\Services;

/**
 * Evaluates routing rules for a scan.
 *
 * Semantics, chosen to be obvious to a non-technical user:
 *   - Rules are tried in priority order; the first match wins.
 *   - Within one condition, the listed values are OR'd ("iOS or Android").
 *   - Across conditions they are AND'd ("iOS AND in the UK").
 *   - An absent or empty condition places no constraint.
 *
 * If nothing matches, the link's default destination is used, so a scan
 * can never dead-end.
 */
final class RuleEngine
{
    public const CONDITION_TYPES = [
        'device', 'os', 'browser', 'country', 'lang', 'referer', 'schedule', 'window', 'scans',
    ];

    /**
     * @param list<array<string,mixed>> $rules  ordered by priority ASC
     * @param array<string,mixed>       $context
     * @return array{rule:?array<string,mixed>,url:string}
     */
    public static function resolve(array $rules, array $context, string $defaultUrl): array
    {
        foreach ($rules as $rule) {
            if ((int) ($rule['is_active'] ?? 1) !== 1) {
                continue;
            }
            $conditions = self::decode((string) ($rule['conditions'] ?? '{}'));
            if ($conditions === [] ) {
                // A rule with no conditions matches everything. Treated as a
                // deliberate catch-all rather than an error.
                return ['rule' => $rule, 'url' => (string) $rule['target_url']];
            }
            if (self::matches($conditions, $context)) {
                return ['rule' => $rule, 'url' => (string) $rule['target_url']];
            }
        }
        return ['rule' => null, 'url' => $defaultUrl];
    }

    /**
     * @param array<string,mixed> $conditions
     * @param array<string,mixed> $context
     */
    public static function matches(array $conditions, array $context): bool
    {
        foreach ($conditions as $type => $spec) {
            if (!self::matchOne((string) $type, $spec, $context)) {
                return false;
            }
        }
        return true;
    }

    /** @param array<string,mixed> $context */
    private static function matchOne(string $type, mixed $spec, array $context): bool
    {
        switch ($type) {
            case 'device':
            case 'os':
            case 'browser':
            case 'country':
            case 'lang':
                if (!is_array($spec) || $spec === []) {
                    return true;
                }
                $actual = strtolower((string) ($context[$type] ?? ''));
                foreach ($spec as $wanted) {
                    if (strtolower((string) $wanted) === $actual) {
                        return true;
                    }
                }
                return false;

            case 'referer':
                if (!is_array($spec) || $spec === []) {
                    return true;
                }
                $host = strtolower((string) ($context['referer'] ?? ''));
                if ($host === '') {
                    return false;
                }
                foreach ($spec as $wanted) {
                    $w = strtolower(trim((string) $wanted));
                    if ($w === '') {
                        continue;
                    }
                    // Match the domain and any subdomain of it.
                    if ($host === $w || str_ends_with($host, '.' . $w)) {
                        return true;
                    }
                }
                return false;

            case 'schedule':
                return self::matchSchedule(is_array($spec) ? $spec : [], $context);

            case 'window':
                return self::matchWindow(is_array($spec) ? $spec : [], $context);

            case 'scans':
                if (!is_array($spec)) {
                    return true;
                }
                $count = (int) ($context['scan_count'] ?? 0);
                if (isset($spec['max']) && $count >= (int) $spec['max']) {
                    return false;
                }
                if (isset($spec['min']) && $count < (int) $spec['min']) {
                    return false;
                }
                return true;

            default:
                // An unknown condition type must never silently match, or a
                // typo in the API would route traffic somewhere unintended.
                return false;
        }
    }

    /**
     * @param array<string,mixed> $spec
     * @param array<string,mixed> $context
     */
    private static function matchSchedule(array $spec, array $context): bool
    {
        $tz = (string) ($spec['tz'] ?? 'UTC');
        try {
            $zone = new \DateTimeZone($tz);
        } catch (\Throwable) {
            $zone = new \DateTimeZone('UTC');
        }

        $now = (new \DateTimeImmutable('@' . (int) ($context['now'] ?? time())))->setTimezone($zone);

        $days = $spec['days'] ?? null;
        if (is_array($days) && $days !== []) {
            // 1 = Monday through 7 = Sunday, matching ISO-8601.
            $dow = (int) $now->format('N');
            $allowed = array_map('intval', $days);
            if (!in_array($dow, $allowed, true)) {
                return false;
            }
        }

        $from = (string) ($spec['from'] ?? '');
        $to   = (string) ($spec['to'] ?? '');
        if ($from === '' || $to === '') {
            return true;
        }
        $fromMin = self::minutes($from);
        $toMin   = self::minutes($to);
        if ($fromMin === null || $toMin === null) {
            return true;
        }
        $nowMin = (int) $now->format('G') * 60 + (int) $now->format('i');

        if ($fromMin <= $toMin) {
            return $nowMin >= $fromMin && $nowMin < $toMin;
        }
        // Overnight window, e.g. 22:00 to 02:00.
        return $nowMin >= $fromMin || $nowMin < $toMin;
    }

    /**
     * @param array<string,mixed> $spec
     * @param array<string,mixed> $context
     */
    private static function matchWindow(array $spec, array $context): bool
    {
        $now = (int) ($context['now'] ?? time());
        $from = (string) ($spec['from'] ?? '');
        $to   = (string) ($spec['to'] ?? '');

        if ($from !== '') {
            $ts = strtotime($from . ' 00:00:00 UTC');
            if ($ts !== false && $now < $ts) {
                return false;
            }
        }
        if ($to !== '') {
            // The end date is inclusive: "to 2026-12-31" means through the
            // whole of the 31st, which is what a human means by it.
            $ts = strtotime($to . ' 23:59:59 UTC');
            if ($ts !== false && $now > $ts) {
                return false;
            }
        }
        return true;
    }

    private static function minutes(string $hhmm): ?int
    {
        if (preg_match('/^([01]?\d|2[0-3]):([0-5]\d)$/', trim($hhmm), $m) !== 1) {
            return null;
        }
        return (int) $m[1] * 60 + (int) $m[2];
    }

    /** @return array<string,mixed> */
    public static function decode(string $json): array
    {
        $data = json_decode($json, true, 8, JSON_INVALID_UTF8_SUBSTITUTE);
        return is_array($data) ? $data : [];
    }

    /**
     * Validates and normalises conditions submitted by a user.
     *
     * @param array<string,mixed> $input
     * @return array{ok:bool,conditions:array<string,mixed>,error:string}
     */
    public static function sanitize(array $input): array
    {
        $out = [];

        $enums = [
            'device'  => DeviceDetector::DEVICES,
            'os'      => DeviceDetector::OSES,
            'browser' => ['chrome', 'safari', 'firefox', 'edge', 'opera', 'samsung', 'other'],
        ];
        foreach ($enums as $key => $allowed) {
            $values = $input[$key] ?? [];
            if (!is_array($values) || $values === []) {
                continue;
            }
            $clean = [];
            foreach ($values as $v) {
                $v = strtolower(trim((string) $v));
                if (in_array($v, $allowed, true) && !in_array($v, $clean, true)) {
                    $clean[] = $v;
                }
            }
            if ($clean !== []) {
                $out[$key] = $clean;
            }
        }

        $countries = $input['country'] ?? [];
        if (is_array($countries) && $countries !== []) {
            $clean = [];
            foreach ($countries as $c) {
                $c = strtoupper(trim((string) $c));
                if (preg_match('/^[A-Z]{2}$/', $c) === 1 && !in_array($c, $clean, true)) {
                    $clean[] = $c;
                }
            }
            if ($clean !== []) {
                $out['country'] = array_slice($clean, 0, 60);
            }
        }

        $langs = $input['lang'] ?? [];
        if (is_array($langs) && $langs !== []) {
            $clean = [];
            foreach ($langs as $l) {
                $l = strtolower(trim((string) $l));
                if (preg_match('/^[a-z]{2,3}$/', $l) === 1 && !in_array($l, $clean, true)) {
                    $clean[] = $l;
                }
            }
            if ($clean !== []) {
                $out['lang'] = array_slice($clean, 0, 30);
            }
        }

        $referers = $input['referer'] ?? [];
        if (is_array($referers) && $referers !== []) {
            $clean = [];
            foreach ($referers as $r) {
                $r = strtolower(trim((string) $r));
                $r = (string) preg_replace('#^[a-z]+://#', '', $r);
                $r = (string) preg_replace('#/.*$#', '', $r);
                if ($r !== '' && preg_match('/^[a-z0-9.-]{1,191}$/', $r) === 1 && !in_array($r, $clean, true)) {
                    $clean[] = $r;
                }
            }
            if ($clean !== []) {
                $out['referer'] = array_slice($clean, 0, 30);
            }
        }

        $schedule = $input['schedule'] ?? [];
        if (is_array($schedule) && $schedule !== []) {
            $s = [];
            $tz = (string) ($schedule['tz'] ?? '');
            if ($tz !== '' && in_array($tz, \DateTimeZone::listIdentifiers(), true)) {
                $s['tz'] = $tz;
            }
            $days = $schedule['days'] ?? [];
            if (is_array($days) && $days !== []) {
                $clean = [];
                foreach ($days as $d) {
                    $d = (int) $d;
                    if ($d >= 1 && $d <= 7 && !in_array($d, $clean, true)) {
                        $clean[] = $d;
                    }
                }
                sort($clean);
                if ($clean !== [] && count($clean) < 7) {
                    $s['days'] = $clean;
                }
            }
            $from = trim((string) ($schedule['from'] ?? ''));
            $to   = trim((string) ($schedule['to'] ?? ''));
            if ($from !== '' && $to !== '') {
                if (self::minutes($from) === null || self::minutes($to) === null) {
                    return ['ok' => false, 'conditions' => [], 'error' => 'Schedule times must be in HH:MM format.'];
                }
                if ($from === $to) {
                    return ['ok' => false, 'conditions' => [], 'error' => 'Schedule start and end time cannot be identical.'];
                }
                $s['from'] = $from;
                $s['to'] = $to;
            } elseif ($from !== '' || $to !== '') {
                return ['ok' => false, 'conditions' => [], 'error' => 'A schedule needs both a start and an end time.'];
            }
            if ($s !== [] && (isset($s['days']) || isset($s['from']))) {
                $s['tz'] = $s['tz'] ?? 'UTC';
                $out['schedule'] = $s;
            }
        }

        $window = $input['window'] ?? [];
        if (is_array($window) && $window !== []) {
            $w = [];
            foreach (['from', 'to'] as $k) {
                $v = trim((string) ($window[$k] ?? ''));
                if ($v === '') {
                    continue;
                }
                if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) !== 1 || strtotime($v) === false) {
                    return ['ok' => false, 'conditions' => [], 'error' => 'Dates must be in YYYY-MM-DD format.'];
                }
                $w[$k] = $v;
            }
            if (isset($w['from'], $w['to']) && strtotime($w['from']) > strtotime($w['to'])) {
                return ['ok' => false, 'conditions' => [], 'error' => 'The start date must be before the end date.'];
            }
            if ($w !== []) {
                $out['window'] = $w;
            }
        }

        $scans = $input['scans'] ?? [];
        if (is_array($scans) && $scans !== []) {
            $s = [];
            foreach (['min', 'max'] as $k) {
                $v = $scans[$k] ?? '';
                if ($v === '' || $v === null) {
                    continue;
                }
                $n = (int) $v;
                if ($n < 0 || $n > 100_000_000) {
                    return ['ok' => false, 'conditions' => [], 'error' => 'Scan count limits must be between 0 and 100,000,000.'];
                }
                $s[$k] = $n;
            }
            if (isset($s['min'], $s['max']) && $s['min'] >= $s['max']) {
                return ['ok' => false, 'conditions' => [], 'error' => 'The minimum scan count must be below the maximum.'];
            }
            if ($s !== []) {
                $out['scans'] = $s;
            }
        }

        return ['ok' => true, 'conditions' => $out, 'error' => ''];
    }

    /**
     * Renders conditions as a plain-English sentence for the dashboard.
     *
     * @param array<string,mixed> $conditions
     */
    public static function describe(array $conditions): string
    {
        if ($conditions === []) {
            return 'Everyone';
        }
        $parts = [];
        $labels = [
            'device'  => 'on',
            'os'      => 'running',
            'browser' => 'using',
            'country' => 'in',
            'lang'    => 'speaking',
            'referer' => 'coming from',
        ];
        foreach ($labels as $key => $verb) {
            if (!isset($conditions[$key]) || !is_array($conditions[$key])) {
                continue;
            }
            $values = array_map(static function ($v) use ($key): string {
                $v = (string) $v;
                return match ($key) {
                    'os'      => ['ios' => 'iOS', 'macos' => 'macOS', 'chromeos' => 'ChromeOS'][$v] ?? ucfirst($v),
                    'country' => GeoResolver::countryList()[strtoupper($v)] ?? strtoupper($v),
                    'lang'    => strtoupper($v),
                    default   => ucfirst($v),
                };
            }, $conditions[$key]);
            $parts[] = $verb . ' ' . self::humanList($values);
        }

        if (isset($conditions['schedule']) && is_array($conditions['schedule'])) {
            $s = $conditions['schedule'];
            $bits = [];
            if (isset($s['days']) && is_array($s['days'])) {
                $names = [1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 5 => 'Fri', 6 => 'Sat', 7 => 'Sun'];
                $bits[] = implode('/', array_map(static fn($d) => $names[(int) $d] ?? '', $s['days']));
            }
            if (isset($s['from'], $s['to'])) {
                $bits[] = 'between ' . $s['from'] . ' and ' . $s['to'];
            }
            if ($bits !== []) {
                $parts[] = implode(' ', $bits) . ' (' . ($s['tz'] ?? 'UTC') . ')';
            }
        }

        if (isset($conditions['window']) && is_array($conditions['window'])) {
            $w = $conditions['window'];
            if (isset($w['from'], $w['to'])) {
                $parts[] = 'from ' . $w['from'] . ' to ' . $w['to'];
            } elseif (isset($w['from'])) {
                $parts[] = 'from ' . $w['from'];
            } elseif (isset($w['to'])) {
                $parts[] = 'until ' . $w['to'];
            }
        }

        if (isset($conditions['scans']) && is_array($conditions['scans'])) {
            $s = $conditions['scans'];
            if (isset($s['max']) && isset($s['min'])) {
                $parts[] = 'for scans ' . ((int) $s['min'] + 1) . '-' . (int) $s['max'];
            } elseif (isset($s['max'])) {
                $parts[] = 'for the first ' . (int) $s['max'] . ' scans';
            } elseif (isset($s['min'])) {
                $parts[] = 'after ' . (int) $s['min'] . ' scans';
            }
        }

        return 'Anyone ' . implode(', ', $parts);
    }

    /** @param list<string> $items */
    private static function humanList(array $items): string
    {
        $n = count($items);
        if ($n === 0) {
            return '';
        }
        if ($n === 1) {
            return $items[0];
        }
        if ($n <= 4) {
            $last = array_pop($items);
            return implode(', ', $items) . ' or ' . $last;
        }
        return $items[0] . ', ' . $items[1] . ' or ' . ($n - 2) . ' others';
    }
}
