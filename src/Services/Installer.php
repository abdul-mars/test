<?php
declare(strict_types=1);

namespace QRoute\Services;

use PDO;
use QRoute\Core\Config;
use QRoute\Core\Database;
use QRoute\Core\Migrator;
use QRoute\Core\Security;
use QRoute\Models\Setting;
use QRoute\Models\User;

/**
 * First-run setup.
 *
 * Drives both the web wizard and `php bin/console install`, so the two
 * cannot drift apart. Every step is separately callable and reports what
 * it found rather than dying, because the whole point of an installer is
 * to explain what is wrong to someone who cannot read a stack trace.
 *
 * Once complete it writes a lock file. The application refuses to serve
 * the installer after that: a reachable installer on a live site is a
 * standing invitation to have the database repointed by a stranger.
 */
final class Installer
{
    public const LOCK_FILE = 'storage/installed.lock';

    /** Minimum PHP version the codebase relies on. */
    private const MIN_PHP = '8.2.0';

    public static function root(): string
    {
        return dirname(__DIR__, 2);
    }

    public static function lockPath(): string
    {
        return self::root() . '/' . self::LOCK_FILE;
    }

    public static function envPath(): string
    {
        return self::root() . '/.env';
    }

    /** True once setup has completed successfully. */
    public static function isInstalled(): bool
    {
        return is_file(self::lockPath());
    }

    public static function lock(): void
    {
        $dir = dirname(self::lockPath());
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        file_put_contents(
            self::lockPath(),
            "QRoute was installed on " . gmdate('c') . ".\n"
            . "Delete this file only if you intend to run the installer again.\n"
        );
    }

    // ------------------------------------------------------- requirements

    /**
     * @return list<array{name:string,ok:bool,required:bool,detail:string}>
     */
    public static function requirements(): array
    {
        $checks = [];

        $checks[] = [
            'name'     => 'PHP ' . self::MIN_PHP . ' or newer',
            'ok'       => version_compare(PHP_VERSION, self::MIN_PHP, '>='),
            'required' => true,
            'detail'   => 'You have ' . PHP_VERSION,
        ];

        foreach ([
            'pdo'        => 'Database access',
            'pdo_mysql'  => 'MySQL / MariaDB driver',
            'mbstring'   => 'Unicode text handling',
            'json'       => 'JSON encoding',
        ] as $ext => $why) {
            $checks[] = [
                'name'     => "Extension: {$ext}",
                'ok'       => extension_loaded($ext),
                'required' => true,
                'detail'   => $why . (extension_loaded($ext) ? '' : ' — enable it in php.ini'),
            ];
        }

        $checks[] = [
            'name'     => 'Extension: gd',
            'ok'       => extension_loaded('gd'),
            'required' => false,
            'detail'   => extension_loaded('gd')
                ? 'PNG downloads available'
                : 'Without it, codes download as SVG only (still fine for print)',
        ];

        $checks[] = [
            'name'     => 'Extension: pdo_sqlite',
            'ok'       => extension_loaded('pdo_sqlite'),
            'required' => false,
            'detail'   => 'Only needed if you choose SQLite instead of MySQL',
        ];

        $storage = self::root() . '/storage';
        $storageWritable = is_dir($storage) ? is_writable($storage) : is_writable(self::root());
        $checks[] = [
            'name'     => 'storage/ is writable',
            'ok'       => $storageWritable,
            'required' => true,
            'detail'   => $storageWritable
                ? 'Used for the install lock and session cleanup'
                : 'Grant write permission to ' . $storage,
        ];

        $envWritable = is_file(self::envPath())
            ? is_writable(self::envPath())
            : is_writable(self::root());
        $checks[] = [
            'name'     => '.env is writable',
            'ok'       => $envWritable,
            'required' => false,
            'detail'   => $envWritable
                ? 'Settings will be saved automatically'
                : 'Not writable — the installer will show you what to paste in by hand',
        ];

        return $checks;
    }

    /** @param list<array{ok:bool,required:bool}> $checks */
    public static function requirementsMet(array $checks): bool
    {
        foreach ($checks as $check) {
            if ($check['required'] && !$check['ok']) {
                return false;
            }
        }
        return true;
    }

    // ---------------------------------------------------------- database

    /**
     * Tests a database connection, optionally creating the database.
     *
     * @param array<string,string> $config
     * @return array{ok:bool,error:string,created:bool,server:string}
     */
    public static function testDatabase(array $config, bool $createIfMissing = true): array
    {
        $driver = $config['DB_DRIVER'] ?? 'mysql';

        if ($driver === 'sqlite') {
            $path = $config['DB_PATH'] ?? 'storage/qroute.sqlite';
            if (!str_starts_with($path, '/')) {
                $path = self::root() . '/' . ltrim($path, '/');
            }
            $dir = dirname($path);
            if (!is_dir($dir) && !@mkdir($dir, 0775, true)) {
                return ['ok' => false, 'error' => "Cannot create the folder {$dir}.", 'created' => false, 'server' => ''];
            }
            if (!is_writable($dir)) {
                return ['ok' => false, 'error' => "The folder {$dir} is not writable.", 'created' => false, 'server' => ''];
            }
            try {
                $pdo = new PDO('sqlite:' . $path, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
                return ['ok' => true, 'error' => '', 'created' => true, 'server' => 'SQLite ' . $pdo->getAttribute(PDO::ATTR_SERVER_VERSION)];
            } catch (\Throwable $e) {
                return ['ok' => false, 'error' => $e->getMessage(), 'created' => false, 'server' => ''];
            }
        }

        $host = $config['DB_HOST'] ?? '127.0.0.1';
        $port = (int) ($config['DB_PORT'] ?? 3306);
        $name = $config['DB_NAME'] ?? 'qroute';
        $user = $config['DB_USER'] ?? 'root';
        $pass = $config['DB_PASS'] ?? '';

        if (preg_match('/^[A-Za-z0-9_]{1,64}$/', $name) !== 1) {
            return [
                'ok' => false,
                'error' => 'Database name must be 1-64 characters, using letters, numbers and underscores only.',
                'created' => false, 'server' => '',
            ];
        }

        $options = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 5];

        // Connect without a database first so we can report "server is fine,
        // database is missing" separately from "cannot reach the server".
        try {
            $pdo = new PDO("mysql:host={$host};port={$port};charset=utf8mb4", $user, $pass, $options);
        } catch (\PDOException $e) {
            return ['ok' => false, 'error' => self::friendlyDbError($e, $host, $port), 'created' => false, 'server' => ''];
        }

        $server = (string) $pdo->getAttribute(PDO::ATTR_SERVER_VERSION);
        $created = false;

        $exists = $pdo->prepare('SELECT SCHEMA_NAME FROM INFORMATION_SCHEMA.SCHEMATA WHERE SCHEMA_NAME = ?');
        $exists->execute([$name]);

        if ($exists->fetchColumn() === false) {
            if (!$createIfMissing) {
                return [
                    'ok' => false,
                    'error' => "The database \"{$name}\" does not exist. Create it in phpMyAdmin, or tick the box to have it created for you.",
                    'created' => false, 'server' => $server,
                ];
            }
            try {
                // The name is validated against a strict pattern above;
                // MySQL does not allow it as a bound parameter here.
                $pdo->exec("CREATE DATABASE `{$name}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
                $created = true;
            } catch (\PDOException $e) {
                return [
                    'ok' => false,
                    'error' => "Could not create the database \"{$name}\": " . $e->getMessage()
                        . ' You may need to create it yourself in phpMyAdmin.',
                    'created' => false, 'server' => $server,
                ];
            }
        }

        try {
            new PDO("mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4", $user, $pass, $options);
        } catch (\PDOException $e) {
            return ['ok' => false, 'error' => self::friendlyDbError($e, $host, $port), 'created' => $created, 'server' => $server];
        }

        return ['ok' => true, 'error' => '', 'created' => $created, 'server' => $server];
    }

    /** Turns a driver exception into something a person can act on. */
    private static function friendlyDbError(\PDOException $e, string $host, int $port): string
    {
        $code = (int) ($e->errorInfo[1] ?? 0);
        return match (true) {
            $code === 1045 => 'The database username or password was rejected. '
                . 'On a stock XAMPP install this is user "root" with an empty password.',
            $code === 1049 => 'That database does not exist yet.',
            $code === 2002 || str_contains($e->getMessage(), 'refused') =>
                "Could not reach a database server at {$host}:{$port}. "
                . 'Is MySQL started in the XAMPP Control Panel?',
            default => $e->getMessage(),
        };
    }

    // ------------------------------------------------------------- steps

    /**
     * Applies the schema. Safe to re-run; migrations are forward-only and
     * each is recorded once applied.
     *
     * @param array<string,string> $config
     * @return array{ok:bool,applied:list<string>,error:string}
     */
    public static function migrate(array $config): array
    {
        foreach ($config as $key => $value) {
            Config::set($key, (string) $value);
        }
        Database::reset();

        try {
            $migrator = new Migrator(Database::instance(), self::root() . '/migrations');
            return ['ok' => true, 'applied' => $migrator->migrate(), 'error' => ''];
        } catch (\Throwable $e) {
            return ['ok' => false, 'applied' => [], 'error' => $e->getMessage()];
        }
    }

    /**
     * Creates the first administrator.
     *
     * @return array{ok:bool,user:?User,error:string}
     */
    public static function createAdmin(string $email, string $password, string $displayName = ''): array
    {
        $existing = User::findByEmail($email);
        if ($existing !== null) {
            // Re-running setup with the same address promotes rather than
            // failing, which is the forgiving behaviour someone retrying a
            // half-finished install expects.
            $existing->setAdmin(true);
            $existing->setPlan(User::PLAN_TEAM, null);
            return ['ok' => true, 'user' => $existing, 'error' => ''];
        }

        $result = User::register($email, $password, $displayName !== '' ? $displayName : 'Administrator');
        if (!$result['ok'] || $result['user'] === null) {
            return ['ok' => false, 'user' => null, 'error' => $result['error']];
        }

        $admin = $result['user'];
        $admin->setAdmin(true);
        // The operator's own account should not be limited by a plan meant
        // for paying customers.
        $admin->setPlan(User::PLAN_TEAM, null);

        return ['ok' => true, 'user' => $admin, 'error' => ''];
    }

    // --------------------------------------------------------------- env

    /**
     * Writes .env, preserving any comments and keys already there.
     *
     * @param array<string,string> $values
     * @return array{ok:bool,error:string,contents:string}
     */
    public static function writeEnv(array $values): array
    {
        $path = self::envPath();
        $template = is_file($path) ? (string) file_get_contents($path) : self::envTemplate();

        foreach ($values as $key => $value) {
            $line = $key . '=' . self::quoteEnvValue((string) $value);
            $pattern = '/^#?\s*' . preg_quote($key, '/') . '=.*$/m';
            if (preg_match($pattern, $template) === 1) {
                $template = (string) preg_replace($pattern, $line, $template, 1);
            } else {
                $template = rtrim($template) . "\n" . $line . "\n";
            }
        }

        if (!is_writable(is_file($path) ? $path : dirname($path))) {
            return ['ok' => false, 'error' => 'The .env file is not writable.', 'contents' => $template];
        }

        $written = @file_put_contents($path, $template);
        if ($written === false) {
            return ['ok' => false, 'error' => 'Could not write the .env file.', 'contents' => $template];
        }
        @chmod($path, 0640);

        return ['ok' => true, 'error' => '', 'contents' => $template];
    }

    /** Values containing whitespace or quotes are wrapped so they survive a re-read. */
    private static function quoteEnvValue(string $value): string
    {
        if ($value === '' ) {
            return '';
        }
        if (preg_match('/[\s"\'#]/', $value) === 1) {
            return '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $value) . '"';
        }
        return $value;
    }

    private static function envTemplate(): string
    {
        $example = self::root() . '/.env.example';
        return is_file($example) ? (string) file_get_contents($example) : '';
    }

    /**
     * A temporary key used only while installing, so CSRF protection works
     * on the wizard's own forms before APP_KEY exists. Replaced by the real
     * key as soon as setup finishes.
     */
    public static function bootstrapKey(): string
    {
        $path = self::root() . '/storage/install.key';
        if (is_file($path)) {
            $key = trim((string) file_get_contents($path));
            if ($key !== '') {
                return $key;
            }
        }
        if (!is_dir(dirname($path))) {
            @mkdir(dirname($path), 0775, true);
        }
        $key = Security::generateKey();
        @file_put_contents($path, $key);
        @chmod($path, 0600);
        return $key;
    }

    public static function clearBootstrapKey(): void
    {
        $path = self::root() . '/storage/install.key';
        if (is_file($path)) {
            @unlink($path);
        }
    }

    /** Records install metadata once everything has succeeded. */
    public static function finalise(string $adminEmail): void
    {
        try {
            Setting::set('installed_at', (string) time());
            Setting::set('installed_by', $adminEmail);
            Setting::set('schema_version', '002');
        } catch (\Throwable) {
            // The lock file is what actually gates the installer; these are
            // for the operator's benefit only.
        }
        self::lock();
        self::clearBootstrapKey();
    }
}
