<?php
declare(strict_types=1);

namespace QRoute\Core;

use PDO;
use PDOException;
use PDOStatement;

/**
 * Thin PDO wrapper. Supports SQLite (zero-config default, ideal for a
 * single cheap VPS) and MySQL (for when the traffic justifies it).
 *
 * Every query in the application goes through here with bound parameters;
 * there is no string interpolation of user input anywhere in the codebase.
 */
final class Database
{
    private static ?Database $instance = null;
    private PDO $pdo;
    private string $driver;

    private function __construct()
    {
        $driver = strtolower((string) Config::get('DB_DRIVER', 'sqlite'));
        $this->driver = $driver;

        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::ATTR_STRINGIFY_FETCHES  => false,
        ];

        if ($driver === 'sqlite') {
            $path = (string) Config::get('DB_PATH', 'storage/qroute.sqlite');
            $path = self::resolveSqlitePath($path);
            if ($path !== ':memory:') {
                $dir = dirname($path);
                if (!is_dir($dir)) {
                    mkdir($dir, 0775, true);
                }
            }
            $this->pdo = new PDO('sqlite:' . $path, null, null, $options);
            // WAL keeps readers from blocking on the redirect hot path while
            // a scan is being written.
            $this->pdo->exec('PRAGMA journal_mode = WAL');
            $this->pdo->exec('PRAGMA synchronous = NORMAL');
            $this->pdo->exec('PRAGMA foreign_keys = ON');
            $this->pdo->exec('PRAGMA busy_timeout = 5000');
        } elseif ($driver === 'mysql') {
            $dsn = sprintf(
                'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
                Config::get('DB_HOST', '127.0.0.1'),
                Config::int('DB_PORT', 3306),
                Config::get('DB_NAME', 'qroute')
            );
            $this->pdo = new PDO(
                $dsn,
                Config::get('DB_USER', 'root'),
                Config::get('DB_PASS', ''),
                $options
            );
        } else {
            throw new \RuntimeException("Unsupported DB_DRIVER: {$driver}");
        }
    }

    /**
     * Resolves a SQLite path against the project root rather than the
     * current working directory.
     *
     * This matters more than it looks: under php-fpm the working directory
     * is the script's own directory, which is public/. A relative DB_PATH
     * would put the database inside the web root, where the password and
     * API key hashes it contains would be downloadable. Anchoring to the
     * project root makes that impossible to configure by accident.
     */
    private static function resolveSqlitePath(string $path): string
    {
        if ($path === ':memory:' || str_starts_with($path, 'file:')) {
            return $path;
        }
        // Absolute paths (POSIX or Windows) are taken as given.
        if (str_starts_with($path, '/') || preg_match('#^[A-Za-z]:[\\/]#', $path) === 1) {
            return $path;
        }
        return dirname(__DIR__, 2) . '/' . ltrim($path, '/');
    }

    public static function instance(): Database
    {
        return self::$instance ??= new self();
    }

    /** Drops the cached connection. Used between test cases. */
    public static function reset(): void
    {
        self::$instance = null;
    }

    public function pdo(): PDO
    {
        return $this->pdo;
    }

    public function driver(): string
    {
        return $this->driver;
    }

    public function isSqlite(): bool
    {
        return $this->driver === 'sqlite';
    }

    /** @param array<string|int,mixed> $params */
    public function run(string $sql, array $params = []): PDOStatement
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }

    /**
     * @param array<string|int,mixed> $params
     * @return array<string,mixed>|null
     */
    public function first(string $sql, array $params = []): ?array
    {
        $row = $this->run($sql, $params)->fetch();
        return $row === false ? null : $row;
    }

    /**
     * @param array<string|int,mixed> $params
     * @return list<array<string,mixed>>
     */
    public function all(string $sql, array $params = []): array
    {
        return $this->run($sql, $params)->fetchAll();
    }

    /** @param array<string|int,mixed> $params */
    public function scalar(string $sql, array $params = []): mixed
    {
        $v = $this->run($sql, $params)->fetchColumn();
        return $v === false ? null : $v;
    }

    /** @param array<string,mixed> $data */
    public function insert(string $table, array $data): int
    {
        $cols = array_keys($data);
        $sql = sprintf(
            'INSERT INTO %s (%s) VALUES (%s)',
            $this->quoteIdent($table),
            implode(', ', array_map([$this, 'quoteIdent'], $cols)),
            implode(', ', array_map(static fn($c) => ':' . $c, $cols))
        );
        $this->run($sql, $data);
        return (int) $this->pdo->lastInsertId();
    }

    /**
     * @param array<string,mixed> $data
     * @param array<string,mixed> $where
     */
    public function update(string $table, array $data, array $where): int
    {
        $set = [];
        $params = [];
        foreach ($data as $k => $v) {
            $set[] = $this->quoteIdent($k) . ' = :s_' . $k;
            $params['s_' . $k] = $v;
        }
        $cond = [];
        foreach ($where as $k => $v) {
            $cond[] = $this->quoteIdent($k) . ' = :w_' . $k;
            $params['w_' . $k] = $v;
        }
        $sql = sprintf(
            'UPDATE %s SET %s WHERE %s',
            $this->quoteIdent($table),
            implode(', ', $set),
            implode(' AND ', $cond)
        );
        return $this->run($sql, $params)->rowCount();
    }

    public function transaction(callable $fn): mixed
    {
        $this->pdo->beginTransaction();
        try {
            $result = $fn($this);
            $this->pdo->commit();
            return $result;
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Identifiers only ever come from application constants, never from
     * request data, but we quote them anyway so a future caller cannot
     * turn a column name into an injection point.
     */
    public function quoteIdent(string $ident): string
    {
        $clean = preg_replace('/[^A-Za-z0-9_]/', '', $ident) ?? '';
        if ($clean === '') {
            throw new \InvalidArgumentException('Invalid identifier');
        }
        return $this->driver === 'mysql' ? "`{$clean}`" : "\"{$clean}\"";
    }

    /** Portable "insert or bump a counter" for the daily rollup table. */
    public function upsertDaily(string $sql, array $params): void
    {
        try {
            $this->run($sql, $params);
        } catch (PDOException $e) {
            throw $e;
        }
    }
}
