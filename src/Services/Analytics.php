<?php
declare(strict_types=1);

namespace QRoute\Services;

use QRoute\Core\Database;
use QRoute\Core\Security;

/**
 * Scan recording and reporting.
 *
 * Two storage tiers:
 *   - `scans` holds raw events and is what the last few months are read
 *     from. It is pruned on a schedule.
 *   - `scan_daily` holds per-day counts by dimension and is what long
 *     ranges are read from, so a two-year chart never touches raw rows.
 *
 * A day is read from `scan_daily` once it has been rolled up and from
 * `scans` before that, never from both, so nothing is ever counted twice.
 */
final class Analytics
{
    public const DIMENSIONS = ['total', 'device', 'os', 'browser', 'country', 'referer', 'rule'];

    /** How long a repeat visit counts as the same person rather than a new scan. */
    private const UNIQUE_WINDOW = 86400;

    /**
     * Writes one scan. Called on the redirect hot path, so it is two
     * statements and no transaction.
     *
     * @param array<string,mixed> $context
     */
    public static function record(int $linkId, int $userId, ?int $ruleId, array $context): void
    {
        $now = (int) ($context['now'] ?? time());
        $visitorHash = substr(
            Security::hmac(
                ($context['ip'] ?? '') . '|' . ($context['ua'] ?? '') . '|' . $linkId,
                'visitor'
            ),
            0,
            32
        );

        $db = Database::instance();

        // A scan is "unique" if we have not seen this visitor on this link
        // within the window. Cheap enough on the indexed column, and it is
        // what makes the dashboard numbers trustworthy.
        $seen = $db->scalar(
            'SELECT 1 FROM scans WHERE link_id = :l AND visitor_hash = :v AND scanned_at > :t LIMIT 1',
            ['l' => $linkId, 'v' => $visitorHash, 't' => $now - self::UNIQUE_WINDOW]
        );

        $db->insert('scans', [
            'link_id'      => $linkId,
            'rule_id'      => $ruleId,
            'user_id'      => $userId,
            'scanned_at'   => $now,
            'day'          => gmdate('Y-m-d', $now),
            'device'       => (string) ($context['device'] ?? 'unknown'),
            'os'           => (string) ($context['os'] ?? 'other'),
            'browser'      => (string) ($context['browser'] ?? 'unknown'),
            'country'      => (string) ($context['country'] ?? ''),
            'lang'         => (string) ($context['lang'] ?? ''),
            'referer_host' => (string) ($context['referer'] ?? ''),
            'is_bot'       => ($context['is_bot'] ?? false) ? 1 : 0,
            'is_unique'    => $seen === null ? 1 : 0,
            'visitor_hash' => $visitorHash,
        ]);
    }

    /** Scans used against the monthly quota (bots excluded). */
    public static function monthlyScanCount(int $userId, ?int $now = null): int
    {
        $now ??= time();
        $monthStart = (int) strtotime(gmdate('Y-m-01 00:00:00', $now) . ' UTC');

        $raw = (int) Database::instance()->scalar(
            'SELECT COUNT(*) FROM scans WHERE user_id = :u AND scanned_at >= :t AND is_bot = 0',
            ['u' => $userId, 't' => $monthStart]
        );

        // Rolled-up days that are no longer in the raw table still count.
        $rolled = (int) Database::instance()->scalar(
            'SELECT COALESCE(SUM(d.total), 0)
               FROM scan_daily d
               JOIN links l ON l.id = d.link_id
              WHERE l.user_id = :u AND d.dimension = :dim AND d.day >= :day
                AND NOT EXISTS (
                    SELECT 1 FROM scans s WHERE s.link_id = d.link_id AND s.day = d.day
                )',
            ['u' => $userId, 'dim' => 'total', 'day' => gmdate('Y-m-01', $now)]
        );

        return $raw + $rolled;
    }

    /**
     * Daily totals for a link over a date range.
     *
     * @return array<string,array{total:int,uniques:int}> keyed by Y-m-d
     */
    public static function daily(int $linkId, int $days = 30, ?int $now = null): array
    {
        $now ??= time();
        $days = max(1, min(1825, $days));
        $startDay = gmdate('Y-m-d', $now - ($days - 1) * 86400);
        $db = Database::instance();

        $series = [];
        for ($i = $days - 1; $i >= 0; $i--) {
            $series[gmdate('Y-m-d', $now - $i * 86400)] = ['total' => 0, 'uniques' => 0];
        }

        foreach ($db->all(
            'SELECT day, total, uniques FROM scan_daily
              WHERE link_id = :l AND dimension = :d AND day >= :s',
            ['l' => $linkId, 'd' => 'total', 's' => $startDay]
        ) as $row) {
            $day = (string) $row['day'];
            if (isset($series[$day])) {
                $series[$day] = ['total' => (int) $row['total'], 'uniques' => (int) $row['uniques']];
            }
        }

        // Days present in the raw table have not been rolled up yet (or are
        // today); the raw count is authoritative for those.
        foreach ($db->all(
            'SELECT day, COUNT(*) AS total, SUM(is_unique) AS uniques
               FROM scans
              WHERE link_id = :l AND day >= :s AND is_bot = 0
              GROUP BY day',
            ['l' => $linkId, 's' => $startDay]
        ) as $row) {
            $day = (string) $row['day'];
            if (isset($series[$day])) {
                $series[$day] = ['total' => (int) $row['total'], 'uniques' => (int) $row['uniques']];
            }
        }

        return $series;
    }

    /**
     * Breakdown by a dimension over a range.
     *
     * @return list<array{value:string,total:int}>
     */
    public static function breakdown(int $linkId, string $dimension, int $days = 30, ?int $now = null, int $limit = 12): array
    {
        if (!in_array($dimension, self::DIMENSIONS, true) || $dimension === 'total') {
            return [];
        }
        $now ??= time();
        $startDay = gmdate('Y-m-d', $now - (max(1, $days) - 1) * 86400);
        $db = Database::instance();

        $column = match ($dimension) {
            'device'  => 'device',
            'os'      => 'os',
            'browser' => 'browser',
            'country' => 'country',
            'referer' => 'referer_host',
            'rule'    => 'rule_id',
            default   => 'device',
        };

        $totals = [];

        foreach ($db->all(
            'SELECT value, SUM(total) AS total FROM scan_daily
              WHERE link_id = :l AND dimension = :dim AND day >= :s
                AND day NOT IN (SELECT DISTINCT day FROM scans WHERE link_id = :l2)
              GROUP BY value',
            ['l' => $linkId, 'dim' => $dimension, 's' => $startDay, 'l2' => $linkId]
        ) as $row) {
            $totals[(string) $row['value']] = (int) $row['total'];
        }

        foreach ($db->all(
            sprintf(
                'SELECT %s AS value, COUNT(*) AS total FROM scans
                  WHERE link_id = :l AND day >= :s AND is_bot = 0
                  GROUP BY %s',
                $db->quoteIdent($column),
                $db->quoteIdent($column)
            ),
            ['l' => $linkId, 's' => $startDay]
        ) as $row) {
            $value = (string) ($row['value'] ?? '');
            $totals[$value] = ($totals[$value] ?? 0) + (int) $row['total'];
        }

        $out = [];
        foreach ($totals as $value => $total) {
            if ($total <= 0) {
                continue;
            }
            $out[] = ['value' => $value === '' ? 'unknown' : (string) $value, 'total' => $total];
        }
        usort($out, static fn(array $a, array $b) => $b['total'] <=> $a['total']);

        return array_slice($out, 0, $limit);
    }

    /**
     * Aggregates completed days into scan_daily, then optionally prunes the
     * raw rows it just summarised.
     *
     * @return array{days:int,rows:int,pruned:int}
     */
    public static function rollup(int $retentionDays = 90, ?int $now = null): array
    {
        $now ??= time();
        $today = gmdate('Y-m-d', $now);
        $db = Database::instance();

        $days = $db->all(
            'SELECT DISTINCT link_id, day FROM scans WHERE day < :today ORDER BY day ASC',
            ['today' => $today]
        );

        $rows = 0;
        foreach ($days as $entry) {
            $linkId = (int) $entry['link_id'];
            $day = (string) $entry['day'];

            $buckets = [
                'total'   => [['value' => 'all']],
                'device'  => $db->all('SELECT device AS v, COUNT(*) c, SUM(is_unique) u FROM scans WHERE link_id=:l AND day=:d AND is_bot=0 GROUP BY device', ['l' => $linkId, 'd' => $day]),
                'os'      => $db->all('SELECT os AS v, COUNT(*) c, SUM(is_unique) u FROM scans WHERE link_id=:l AND day=:d AND is_bot=0 GROUP BY os', ['l' => $linkId, 'd' => $day]),
                'browser' => $db->all('SELECT browser AS v, COUNT(*) c, SUM(is_unique) u FROM scans WHERE link_id=:l AND day=:d AND is_bot=0 GROUP BY browser', ['l' => $linkId, 'd' => $day]),
                'country' => $db->all('SELECT country AS v, COUNT(*) c, SUM(is_unique) u FROM scans WHERE link_id=:l AND day=:d AND is_bot=0 GROUP BY country', ['l' => $linkId, 'd' => $day]),
                'referer' => $db->all('SELECT referer_host AS v, COUNT(*) c, SUM(is_unique) u FROM scans WHERE link_id=:l AND day=:d AND is_bot=0 GROUP BY referer_host', ['l' => $linkId, 'd' => $day]),
                'rule'    => $db->all('SELECT COALESCE(rule_id, 0) AS v, COUNT(*) c, SUM(is_unique) u FROM scans WHERE link_id=:l AND day=:d AND is_bot=0 GROUP BY rule_id', ['l' => $linkId, 'd' => $day]),
            ];

            $totalRow = $db->first(
                'SELECT COUNT(*) c, SUM(is_unique) u FROM scans WHERE link_id=:l AND day=:d AND is_bot=0',
                ['l' => $linkId, 'd' => $day]
            ) ?? ['c' => 0, 'u' => 0];

            $buckets['total'] = [['v' => 'all', 'c' => $totalRow['c'], 'u' => $totalRow['u']]];

            foreach ($buckets as $dimension => $entries) {
                foreach ($entries as $e) {
                    self::upsertDaily(
                        $linkId,
                        $day,
                        (string) $dimension,
                        (string) ($e['v'] ?? ''),
                        (int) ($e['c'] ?? 0),
                        (int) ($e['u'] ?? 0)
                    );
                    $rows++;
                }
            }
        }

        $pruned = 0;
        if ($retentionDays > 0) {
            $cutoff = gmdate('Y-m-d', $now - $retentionDays * 86400);
            $pruned = $db->run('DELETE FROM scans WHERE day < :c', ['c' => $cutoff])->rowCount();
        }

        return ['days' => count($days), 'rows' => $rows, 'pruned' => $pruned];
    }

    private static function upsertDaily(int $linkId, string $day, string $dimension, string $value, int $total, int $uniques): void
    {
        $db = Database::instance();
        $params = [
            'l' => $linkId, 'd' => $day, 'dim' => $dimension,
            'v' => mb_substr($value, 0, 64), 't' => $total, 'u' => $uniques,
        ];
        $sql = $db->isSqlite()
            ? 'INSERT INTO scan_daily (link_id, day, dimension, value, total, uniques)
               VALUES (:l, :d, :dim, :v, :t, :u)
               ON CONFLICT(link_id, day, dimension, value)
               DO UPDATE SET total = excluded.total, uniques = excluded.uniques'
            : 'INSERT INTO scan_daily (link_id, day, dimension, value, total, uniques)
               VALUES (:l, :d, :dim, :v, :t, :u)
               ON DUPLICATE KEY UPDATE total = VALUES(total), uniques = VALUES(uniques)';
        $db->run($sql, $params);
    }

    /** Totals across every link a user owns, for the dashboard header. */
    public static function userSummary(int $userId, int $days = 30, ?int $now = null): array
    {
        $now ??= time();
        $startDay = gmdate('Y-m-d', $now - (max(1, $days) - 1) * 86400);
        $db = Database::instance();

        $row = $db->first(
            'SELECT COUNT(*) AS total, COALESCE(SUM(is_unique), 0) AS uniques
               FROM scans WHERE user_id = :u AND day >= :s AND is_bot = 0',
            ['u' => $userId, 's' => $startDay]
        ) ?? ['total' => 0, 'uniques' => 0];

        return [
            'scans'   => (int) $row['total'],
            'uniques' => (int) $row['uniques'],
            'links'   => (int) $db->scalar(
                'SELECT COUNT(*) FROM links WHERE user_id = :u AND archived_at IS NULL',
                ['u' => $userId]
            ),
        ];
    }

    /**
     * Raw rows for CSV export.
     *
     * @return list<array<string,mixed>>
     */
    public static function export(int $linkId, int $days, ?int $now = null): array
    {
        $now ??= time();
        $startDay = gmdate('Y-m-d', $now - (max(1, $days) - 1) * 86400);
        return Database::instance()->all(
            'SELECT scanned_at, day, device, os, browser, country, lang, referer_host, rule_id, is_unique, is_bot
               FROM scans WHERE link_id = :l AND day >= :s ORDER BY scanned_at DESC LIMIT 50000',
            ['l' => $linkId, 's' => $startDay]
        );
    }
}
