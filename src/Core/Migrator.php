<?php
declare(strict_types=1);

namespace QRoute\Core;

/**
 * Minimal forward-only migration runner.
 *
 * Migration files live in /migrations, are named NNN_name.php and return a
 * list of SQL statements. Statements may use portability tokens which are
 * expanded for the active driver, so one migration file serves both SQLite
 * and MySQL without duplicating the schema.
 */
final class Migrator
{
    public function __construct(
        private Database $db,
        private string $dir
    ) {
    }

    public function ensureTable(): void
    {
        $sql = $this->expand(
            'CREATE TABLE IF NOT EXISTS migrations (
                id {{PK}},
                name {{STR}}(191) NOT NULL {{UNIQ}},
                applied_at {{INT}} NOT NULL
            ) {{TABLE_OPTS}}'
        );
        $this->db->pdo()->exec($sql);
    }

    /** @return list<string> names of migrations applied by this call */
    public function migrate(?callable $log = null): array
    {
        $this->ensureTable();
        $applied = [];
        foreach ($this->db->all('SELECT name FROM migrations') as $row) {
            $applied[(string) $row['name']] = true;
        }

        $files = glob(rtrim($this->dir, '/') . '/*.php') ?: [];
        sort($files, SORT_STRING);

        $ran = [];
        foreach ($files as $file) {
            $name = basename($file, '.php');
            if (isset($applied[$name])) {
                continue;
            }
            /** @var list<string> $statements */
            $statements = require $file;
            foreach ($statements as $stmt) {
                $sql = trim($this->expand($stmt));
                if ($sql === '') {
                    continue;
                }
                try {
                    $this->db->pdo()->exec($sql);
                } catch (\PDOException $e) {
                    // MySQL has no "CREATE INDEX IF NOT EXISTS", so a
                    // re-created index arrives as a duplicate-key-name
                    // error instead. That is the same outcome the SQLite
                    // form asks for, so treat it as success.
                    if ($this->isDuplicateIndex($e)) {
                        continue;
                    }
                    throw new \RuntimeException(
                        "Migration {$name} failed on: " . substr($sql, 0, 160) . ' -- ' . $e->getMessage(),
                        0,
                        $e
                    );
                }
            }
            $this->db->insert('migrations', ['name' => $name, 'applied_at' => time()]);
            $ran[] = $name;
            if ($log) {
                $log($name);
            }
        }
        return $ran;
    }

    /** MySQL error 1061 is ER_DUP_KEYNAME: the index already exists. */
    private function isDuplicateIndex(\PDOException $e): bool
    {
        return ($e->errorInfo[1] ?? null) === 1061;
    }

    private function expand(string $sql): string
    {
        return self::expandFor($sql, $this->db->driver());
    }

    /**
     * Expands the portability tokens for a named driver.
     *
     * Static and public so the schema dumper can produce MySQL DDL without
     * a live MySQL connection, and without a second copy of the type map
     * that could drift away from this one.
     */
    public static function expandFor(string $sql, string $driver): string
    {
        $mysql = $driver === 'mysql';
        $map = $mysql
            ? [
                '{{PK}}'          => 'BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY',
                '{{FK}}'          => 'BIGINT UNSIGNED',
                '{{INT}}'         => 'BIGINT',
                '{{SMALLINT}}'    => 'INT',
                '{{STR}}'         => 'VARCHAR',
                '{{TEXT}}'        => 'MEDIUMTEXT',
                '{{UNIQ}}'        => 'UNIQUE',
                '{{TABLE_OPTS}}'  => 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
            ]
            : [
                '{{PK}}'          => 'INTEGER PRIMARY KEY AUTOINCREMENT',
                '{{FK}}'          => 'INTEGER',
                '{{INT}}'         => 'INTEGER',
                '{{SMALLINT}}'    => 'INTEGER',
                '{{STR}}'         => 'TEXT',
                '{{TEXT}}'        => 'TEXT',
                '{{UNIQ}}'        => 'UNIQUE',
                '{{TABLE_OPTS}}'  => '',
            ];

        // SQLite does not accept a length suffix on TEXT columns.
        if (!$mysql) {
            $sql = preg_replace('/\{\{STR\}\}\(\d+\)/', '{{STR}}', $sql) ?? $sql;
        }

        // "CREATE INDEX IF NOT EXISTS" is SQLite and MariaDB syntax; MySQL
        // rejects it outright. Dropping the clause keeps one migration file
        // working on all three, with the duplicate-index error handled by
        // the caller.
        if ($mysql) {
            $sql = preg_replace(
                '/^(\s*CREATE\s+(?:UNIQUE\s+)?INDEX\s+)IF\s+NOT\s+EXISTS\s+/i',
                '$1',
                $sql
            ) ?? $sql;
        }

        return strtr($sql, $map);
    }
}
