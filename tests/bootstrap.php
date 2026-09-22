<?php
declare(strict_types=1);

/**
 * Minimal test harness.
 *
 * No PHPUnit: the point of this application is that it runs on a stock PHP
 * install with no vendor directory, and the test suite holds itself to the
 * same standard so it can run anywhere the app can.
 */

require __DIR__ . '/../src/bootstrap.php';

use QRoute\Core\Config;
use QRoute\Core\Database;
use QRoute\Core\Migrator;

final class TestRunner
{
    private int $passed = 0;
    private int $failed = 0;
    /** @var list<string> */
    private array $failures = [];
    private string $group = '';

    public function group(string $name): void
    {
        $this->group = $name;
        fwrite(STDOUT, "\n\033[1m{$name}\033[0m\n");
    }

    public function ok(bool $condition, string $description, string $detail = ''): void
    {
        if ($condition) {
            $this->passed++;
            fwrite(STDOUT, "  \033[32m✓\033[0m {$description}\n");
            return;
        }
        $this->failed++;
        $line = $this->group . ' → ' . $description . ($detail !== '' ? "\n      {$detail}" : '');
        $this->failures[] = $line;
        fwrite(STDOUT, "  \033[31m✗ {$description}\033[0m\n");
        if ($detail !== '') {
            fwrite(STDOUT, "      {$detail}\n");
        }
    }

    public function same(mixed $expected, mixed $actual, string $description): void
    {
        $this->ok(
            $expected === $actual,
            $description,
            $expected === $actual ? '' : 'expected ' . $this->show($expected) . ', got ' . $this->show($actual)
        );
    }

    public function throws(callable $fn, string $description, ?string $class = null): void
    {
        try {
            $fn();
            $this->ok(false, $description, 'no exception was thrown');
        } catch (\Throwable $e) {
            $this->ok(
                $class === null || $e instanceof $class,
                $description,
                $class === null || $e instanceof $class ? '' : 'got ' . $e::class
            );
        }
    }

    private function show(mixed $v): string
    {
        if (is_bool($v)) {
            return $v ? 'true' : 'false';
        }
        if (is_string($v)) {
            return '"' . (strlen($v) > 80 ? substr($v, 0, 77) . '...' : $v) . '"';
        }
        if (is_array($v)) {
            return 'array(' . count($v) . ') ' . substr(json_encode($v) ?: '', 0, 120);
        }
        return var_export($v, true);
    }

    public function finish(): int
    {
        $total = $this->passed + $this->failed;
        fwrite(STDOUT, "\n" . str_repeat('─', 52) . "\n");
        if ($this->failed === 0) {
            fwrite(STDOUT, "\033[32mAll {$total} assertions passed.\033[0m\n");
            return 0;
        }
        fwrite(STDOUT, "\033[31m{$this->failed} of {$total} assertions failed:\033[0m\n");
        foreach ($this->failures as $f) {
            fwrite(STDOUT, "  • {$f}\n");
        }
        return 1;
    }
}

/**
 * Builds a throwaway database with the schema applied.
 *
 * Defaults to in-memory SQLite so the suite runs anywhere with no setup.
 * Set DB_DRIVER=mysql (plus the usual DB_* variables) to run the identical
 * assertions against MySQL or MariaDB, which is how the portability of the
 * migrations and the driver-specific upsert paths is actually verified
 * rather than assumed.
 */
function test_database(): void
{
    Config::set('APP_KEY', 'base64:' . base64_encode(str_repeat('t', 32)));
    Config::set('APP_URL', 'https://qrt.test');
    Config::set('BLOCK_PRIVATE_DNS', 'false');
    Config::set('TRUSTED_PROXIES', '');

    $driver = strtolower((string) (getenv('DB_DRIVER') ?: 'sqlite'));

    if ($driver === 'mysql') {
        Config::set('DB_DRIVER', 'mysql');
        foreach (['DB_HOST' => '127.0.0.1', 'DB_PORT' => '3306', 'DB_USER' => 'root', 'DB_PASS' => ''] as $k => $default) {
            Config::set($k, (string) (getenv($k) !== false ? getenv($k) : $default));
        }
        // Never run destructively against a database someone might be using.
        $name = (string) (getenv('DB_NAME') ?: 'qroute_test');
        if (!str_contains($name, 'test')) {
            fwrite(STDERR, "Refusing to run tests against database '{$name}': the name must contain 'test'.\n");
            exit(2);
        }
        Config::set('DB_NAME', $name);

        Database::reset();
        $db = Database::instance();
        // Start from an empty schema so each run is independent.
        $db->pdo()->exec('SET FOREIGN_KEY_CHECKS = 0');
        foreach ($db->all('SHOW TABLES') as $row) {
            $table = (string) array_values($row)[0];
            $db->pdo()->exec('DROP TABLE IF EXISTS ' . $db->quoteIdent($table));
        }
        $db->pdo()->exec('SET FOREIGN_KEY_CHECKS = 1');
    } else {
        Config::set('DB_DRIVER', 'sqlite');
        Config::set('DB_PATH', ':memory:');
        Database::reset();
    }

    $migrator = new Migrator(Database::instance(), __DIR__ . '/../migrations');
    $migrator->migrate();
}

/** Names the engine the suite is currently running against. */
function test_driver(): string
{
    return Database::instance()->driver();
}
