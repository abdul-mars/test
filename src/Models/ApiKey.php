<?php
declare(strict_types=1);

namespace QRoute\Models;

use QRoute\Core\Database;
use QRoute\Core\Security;

/**
 * API keys.
 *
 * Only a SHA-256 of the key is stored, so a database leak does not hand an
 * attacker working credentials. The displayed prefix is enough for a user
 * to tell two keys apart without it being usable on its own.
 */
final class ApiKey
{
    private const PREFIX = 'qr_live_';

    /** @return array{id:int,plaintext:string,prefix:string} */
    public static function create(int $userId, string $name = ''): array
    {
        $secret = Security::randomToken(24);
        $plaintext = self::PREFIX . $secret;
        $prefix = substr($plaintext, 0, 12);

        $id = Database::instance()->insert('api_keys', [
            'user_id'    => $userId,
            'name'       => mb_substr(trim($name), 0, 80),
            'prefix'     => $prefix,
            'key_hash'   => hash('sha256', $plaintext),
            'scopes'     => 'read,write',
            'created_at' => time(),
        ]);

        return ['id' => $id, 'plaintext' => $plaintext, 'prefix' => $prefix];
    }

    /** Resolves a presented key to its owner, or null. */
    public static function authenticate(string $presented): ?User
    {
        $presented = trim($presented);
        if ($presented === '' || !str_starts_with($presented, self::PREFIX)) {
            return null;
        }

        $row = Database::instance()->first(
            'SELECT * FROM api_keys WHERE key_hash = :h AND revoked_at IS NULL',
            ['h' => hash('sha256', $presented)]
        );
        if ($row === null) {
            return null;
        }

        // Touch at most once a minute; this runs on every API call.
        $last = (int) ($row['last_used_at'] ?? 0);
        if (time() - $last > 60) {
            Database::instance()->update('api_keys', ['last_used_at' => time()], ['id' => (int) $row['id']]);
        }

        return User::find((int) $row['user_id']);
    }

    /** @return list<array<string,mixed>> */
    public static function forUser(int $userId): array
    {
        return Database::instance()->all(
            'SELECT id, name, prefix, scopes, last_used_at, created_at
               FROM api_keys WHERE user_id = :u AND revoked_at IS NULL
              ORDER BY created_at DESC',
            ['u' => $userId]
        );
    }

    public static function revoke(int $id, int $userId): bool
    {
        return Database::instance()->update(
            'api_keys',
            ['revoked_at' => time()],
            ['id' => $id, 'user_id' => $userId]
        ) > 0;
    }
}
