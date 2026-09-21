<?php
declare(strict_types=1);

namespace QRoute\Core;

/**
 * Fixed-window rate limiter backed by the database, so it survives a
 * process restart and works across multiple PHP-FPM workers without
 * needing Redis. Redis is used automatically when it is available and
 * configured, because the redirect hot path should not touch SQL twice.
 */
final class RateLimiter
{
    private static ?\Redis $redis = null;
    private static bool $redisChecked = false;

    /**
     * @return array{allowed:bool,remaining:int,retry_after:int}
     */
    public static function hit(string $bucket, int $limit, int $windowSeconds): array
    {
        $now = time();
        $windowStart = $now - ($now % $windowSeconds);
        $resetAt = $windowStart + $windowSeconds;

        $redis = self::redis();
        if ($redis !== null) {
            $key = 'rl:' . $bucket . ':' . $windowStart;
            try {
                $count = (int) $redis->incr($key);
                if ($count === 1) {
                    $redis->expire($key, $windowSeconds + 5);
                }
                return [
                    'allowed'     => $count <= $limit,
                    'remaining'   => max(0, $limit - $count),
                    'retry_after' => max(1, $resetAt - $now),
                ];
            } catch (\Throwable) {
                self::$redis = null; // fall through to SQL
            }
        }

        $db = Database::instance();
        $count = 0;
        try {
            if ($db->isSqlite()) {
                $db->run(
                    'INSERT INTO rate_limits (bucket, window_start, hits) VALUES (:b, :w, 1)
                     ON CONFLICT(bucket, window_start) DO UPDATE SET hits = hits + 1',
                    ['b' => $bucket, 'w' => $windowStart]
                );
            } else {
                $db->run(
                    'INSERT INTO rate_limits (bucket, window_start, hits) VALUES (:b, :w, 1)
                     ON DUPLICATE KEY UPDATE hits = hits + 1',
                    ['b' => $bucket, 'w' => $windowStart]
                );
            }
            $count = (int) $db->scalar(
                'SELECT hits FROM rate_limits WHERE bucket = :b AND window_start = :w',
                ['b' => $bucket, 'w' => $windowStart]
            );
        } catch (\Throwable) {
            // A limiter that is itself broken must not take the site down.
            return ['allowed' => true, 'remaining' => $limit, 'retry_after' => 0];
        }

        return [
            'allowed'     => $count <= $limit,
            'remaining'   => max(0, $limit - $count),
            'retry_after' => max(1, $resetAt - $now),
        ];
    }

    /** Removes windows older than an hour. Called by the GC command. */
    public static function prune(): int
    {
        try {
            return Database::instance()
                ->run('DELETE FROM rate_limits WHERE window_start < :t', ['t' => time() - 3600])
                ->rowCount();
        } catch (\Throwable) {
            return 0;
        }
    }

    private static function redis(): ?\Redis
    {
        if (self::$redisChecked) {
            return self::$redis;
        }
        self::$redisChecked = true;

        $host = Config::get('REDIS_HOST', '');
        if ($host === null || $host === '' || !class_exists(\Redis::class)) {
            return self::$redis = null;
        }
        try {
            $r = new \Redis();
            $r->connect($host, Config::int('REDIS_PORT', 6379), 0.5);
            $pass = Config::get('REDIS_PASSWORD', '');
            if ($pass !== null && $pass !== '') {
                $r->auth($pass);
            }
            return self::$redis = $r;
        } catch (\Throwable) {
            return self::$redis = null;
        }
    }
}
