<?php
declare(strict_types=1);

namespace QRoute\Controllers;

use QRoute\Http\Response;
use QRoute\Models\Link;
use QRoute\Models\Rule;
use QRoute\Services\Analytics;
use QRoute\Services\GeoResolver;
use QRoute\Services\Plan;

final class AnalyticsController extends Controller
{
    public function show(?string $linkId = null): Response
    {
        $user = $this->requireUser();
        $maxDays = Plan::historyDays($user);

        $days = (int) ($this->request->input('days', '30') ?? 30);
        $days = max(1, min($days, $maxDays));

        $link = null;
        if ($linkId !== null) {
            $link = Link::findForUser((int) $linkId, $user->id());
            if ($link === null) {
                throw new HttpError('Code not found.', 404);
            }
        }

        if ($link !== null) {
            $series = Analytics::daily($link->id(), $days);
            $breakdowns = [
                'Device'  => $this->labelTerms(Analytics::breakdown($link->id(), 'device', $days)),
                'OS'      => $this->labelTerms(Analytics::breakdown($link->id(), 'os', $days)),
                'Country' => $this->labelCountries(Analytics::breakdown($link->id(), 'country', $days)),
                'Rule'    => $this->labelRules(Analytics::breakdown($link->id(), 'rule', $days), $link->id()),
            ];
        } else {
            // Account-wide view: sum the per-link series.
            $series = [];
            $breakdowns = ['Device' => [], 'OS' => [], 'Country' => []];
            foreach (Link::forUser($user->id()) as $owned) {
                foreach (Analytics::daily($owned->id(), $days) as $day => $row) {
                    $series[$day]['total']   = ($series[$day]['total'] ?? 0) + $row['total'];
                    $series[$day]['uniques'] = ($series[$day]['uniques'] ?? 0) + $row['uniques'];
                }
                foreach (['Device' => 'device', 'OS' => 'os', 'Country' => 'country'] as $label => $dim) {
                    foreach (Analytics::breakdown($owned->id(), $dim, $days, null, 50) as $row) {
                        $breakdowns[$label][$row['value']] = ($breakdowns[$label][$row['value']] ?? 0) + $row['total'];
                    }
                }
            }
            ksort($series);
            foreach ($breakdowns as $label => $map) {
                arsort($map);
                $rows = [];
                foreach (array_slice($map, 0, 12, true) as $value => $total) {
                    $rows[] = ['value' => (string) $value, 'total' => (int) $total];
                }
                $breakdowns[$label] = $label === 'Country'
                    ? $this->labelCountries($rows)
                    : $this->labelTerms($rows);
            }
        }

        return $this->view('app/analytics', [
            'title'      => 'Analytics — QRoute',
            'activeNav'  => 'analytics',
            'link'       => $link,
            'series'     => $series,
            'breakdowns' => $breakdowns,
            'days'       => $days,
            'maxDays'    => $maxDays,
            'canExport'  => Plan::can($user, 'csv_export'),
        ]);
    }

    public function exportCsv(string $linkId): Response
    {
        $user = $this->requireUser();
        if (!Plan::can($user, 'csv_export')) {
            $this->flash('CSV export is a Pro feature.', 'warning');
            return $this->redirect('/pricing');
        }

        $link = Link::findForUser((int) $linkId, $user->id());
        if ($link === null) {
            throw new HttpError('Code not found.', 404);
        }

        $days = max(1, min((int) ($this->request->input('days', '30') ?? 30), Plan::historyDays($user)));
        $rows = Analytics::export($link->id(), $days);

        $out = fopen('php://temp', 'r+');
        if ($out === false) {
            throw new HttpError('Could not build the export.', 500);
        }
        fputcsv($out, ['scanned_at_utc', 'day', 'device', 'os', 'browser', 'country', 'language', 'referer', 'rule_id', 'unique', 'bot'], ',', '"', '');
        foreach ($rows as $row) {
            fputcsv($out, [
                gmdate('c', (int) $row['scanned_at']),
                $row['day'], $row['device'], $row['os'], $row['browser'],
                $row['country'], $row['lang'], $row['referer_host'],
                $row['rule_id'], $row['is_unique'], $row['is_bot'],
            ], ',', '"', '');
        }
        rewind($out);
        $csv = (string) stream_get_contents($out);
        fclose($out);

        return Response::raw($csv, 'text/csv; charset=UTF-8', 200, [
            'Content-Disposition' => 'attachment; filename="qroute-' . $link->slug() . '-' . $days . 'd.csv"',
            'Cache-Control'       => 'no-store',
        ]);
    }

    /**
     * Turns stored enum values into the names a person would write.
     *
     * @param list<array{value:string,total:int}> $rows
     * @return list<array{value:string,total:int}>
     */
    private function labelTerms(array $rows): array
    {
        static $names = [
            'ios' => 'iOS', 'macos' => 'macOS', 'chromeos' => 'ChromeOS',
            'android' => 'Android', 'windows' => 'Windows', 'linux' => 'Linux',
            'mobile' => 'Phone', 'tablet' => 'Tablet', 'desktop' => 'Desktop',
            'bot' => 'Bot', 'other' => 'Other', 'unknown' => 'Unknown',
            'chrome' => 'Chrome', 'safari' => 'Safari', 'firefox' => 'Firefox',
            'edge' => 'Edge', 'opera' => 'Opera', 'samsung' => 'Samsung Internet',
        ];
        foreach ($rows as &$row) {
            $row['value'] = $names[strtolower($row['value'])] ?? ucfirst($row['value']);
        }
        return $rows;
    }

    /**
     * @param list<array{value:string,total:int}> $rows
     * @return list<array{value:string,total:int}>
     */
    private function labelCountries(array $rows): array
    {
        $names = GeoResolver::countryList();
        foreach ($rows as &$row) {
            $row['value'] = $names[strtoupper($row['value'])] ?? ($row['value'] === '' || $row['value'] === 'unknown' ? 'Unknown' : $row['value']);
        }
        return $rows;
    }

    /**
     * @param list<array{value:string,total:int}> $rows
     * @return list<array{value:string,total:int}>
     */
    private function labelRules(array $rows, int $linkId): array
    {
        $labels = [];
        foreach (Rule::forLink($linkId) as $rule) {
            $labels[(string) $rule->id()] = $rule->label();
        }
        foreach ($rows as &$row) {
            $row['value'] = $row['value'] === '0' || $row['value'] === '' || $row['value'] === 'unknown'
                ? 'Default destination'
                : ($labels[$row['value']] ?? 'Deleted rule');
        }
        return $rows;
    }
}
