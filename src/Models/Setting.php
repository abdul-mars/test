<?php
declare(strict_types=1);

namespace QRoute\Models;

use QRoute\Core\Database;

/**
 * Small key/value store for values written once and read rarely: the
 * install marker, the site name, and anything else an operator sets from
 * the admin panel rather than from a config file.
 *
 * Values are cached per request because several are read on every page.
 */
final class Setting
{
    /** @var array<string,?string>|null */
    private static ?array $cache = null;

    public static function get(string $name, ?string $default = null): ?string
    {
        self::load();
        return self::$cache[$name] ?? $default;
    }

    public static function set(string $name, ?string $value): void
    {
        $db = Database::instance();
        $exists = $db->scalar('SELECT 1 FROM settings WHERE name = :n', ['n' => $name]) !== null;

        if ($exists) {
            $db->update('settings', ['value' => $value, 'updated_at' => time()], ['name' => $name]);
        } else {
            $db->insert('settings', ['name' => $name, 'value' => $value, 'updated_at' => time()]);
        }
        self::$cache[$name] = $value;
    }

    public static function bool(string $name, bool $default = false): bool
    {
        $v = self::get($name);
        return $v === null ? $default : in_array(strtolower($v), ['1', 'true', 'yes', 'on'], true);
    }

    public static function forget(): void
    {
        self::$cache = null;
    }

    private static function load(): void
    {
        if (self::$cache !== null) {
            return;
        }
        self::$cache = [];
        try {
            foreach (Database::instance()->all('SELECT name, value FROM settings') as $row) {
                self::$cache[(string) $row['name']] = $row['value'] === null ? null : (string) $row['value'];
            }
        } catch (\Throwable) {
            // Before the table exists (during install) every lookup is a miss.
        }
    }
}
