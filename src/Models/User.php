<?php
declare(strict_types=1);

namespace QRoute\Models;

use QRoute\Core\Database;
use QRoute\Core\Security;

final class User
{
    public const PLAN_FREE = 'free';
    public const PLAN_PRO  = 'pro';
    public const PLAN_TEAM = 'team';

    private const MAX_FAILED = 8;
    private const LOCK_SECONDS = 900;

    /** @param array<string,mixed> $row */
    private function __construct(private array $row)
    {
    }

    /** @param array<string,mixed> $row */
    public static function fromRow(array $row): self
    {
        return new self($row);
    }

    public static function find(int $id): ?self
    {
        $row = Database::instance()->first('SELECT * FROM users WHERE id = :id', ['id' => $id]);
        return $row === null ? null : new self($row);
    }

    public static function findByEmail(string $email): ?self
    {
        $row = Database::instance()->first(
            'SELECT * FROM users WHERE email = :e',
            ['e' => self::normalizeEmail($email)]
        );
        return $row === null ? null : new self($row);
    }

    public static function normalizeEmail(string $email): string
    {
        return mb_strtolower(trim($email));
    }

    /**
     * @return array{ok:bool,user:?self,error:string}
     */
    public static function register(string $email, string $password, string $displayName = ''): array
    {
        $email = self::normalizeEmail($email);

        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 191) {
            return ['ok' => false, 'user' => null, 'error' => 'Please enter a valid email address.'];
        }
        $pwError = self::passwordProblem($password);
        if ($pwError !== null) {
            return ['ok' => false, 'user' => null, 'error' => $pwError];
        }
        if (self::findByEmail($email) !== null) {
            // Deliberately the same wording a caller shows for a failed
            // login, so this endpoint is not an account-existence oracle.
            return ['ok' => false, 'user' => null, 'error' => 'That email is already registered. Try signing in.'];
        }

        $now = time();
        $id = Database::instance()->insert('users', [
            'email'         => $email,
            'password_hash' => Security::hashPassword($password),
            'display_name'  => mb_substr(trim($displayName), 0, 80),
            'plan'          => self::PLAN_FREE,
            'status'        => 'active',
            'created_at'    => $now,
            'updated_at'    => $now,
        ]);

        return ['ok' => true, 'user' => self::find($id), 'error' => ''];
    }

    public static function passwordProblem(string $password): ?string
    {
        $len = mb_strlen($password);
        if ($len < 10) {
            return 'Password must be at least 10 characters.';
        }
        if ($len > 4096) {
            return 'Password is too long.';
        }
        // Block the handful of passwords that show up in every credential
        // stuffing list. A full breach-corpus check belongs behind an API.
        $common = [
            'password12', 'password123', '1234567890', 'qwertyuiop', 'letmein123',
            'iloveyou12', 'welcome123', 'admin12345', 'passw0rd12', 'changeme12',
        ];
        if (in_array(mb_strtolower($password), $common, true)) {
            return 'That password is too common. Please choose another.';
        }
        return null;
    }

    /**
     * Verifies credentials with lockout. Returns null on failure so the
     * caller cannot distinguish "no such user" from "wrong password".
     */
    public static function attemptLogin(string $email, string $password): ?self
    {
        $user = self::findByEmail($email);

        if ($user === null) {
            // Spend comparable time so response timing does not reveal
            // whether the account exists.
            Security::verifyPassword($password, '$2y$12$usesomesillystringfaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa');
            return null;
        }
        if ($user->isLocked() || $user->status() !== 'active') {
            return null;
        }
        if (!Security::verifyPassword($password, (string) $user->row['password_hash'])) {
            $user->recordFailedLogin();
            return null;
        }

        if (Security::needsRehash((string) $user->row['password_hash'])) {
            Database::instance()->update(
                'users',
                ['password_hash' => Security::hashPassword($password), 'updated_at' => time()],
                ['id' => $user->id()]
            );
        }
        $user->clearFailedLogins();
        return $user;
    }

    public function isLocked(): bool
    {
        $until = $this->row['locked_until'] ?? null;
        return $until !== null && (int) $until > time();
    }

    public function recordFailedLogin(): void
    {
        $failed = (int) ($this->row['failed_logins'] ?? 0) + 1;
        $data = ['failed_logins' => $failed, 'updated_at' => time()];
        if ($failed >= self::MAX_FAILED) {
            $data['locked_until'] = time() + self::LOCK_SECONDS;
            $data['failed_logins'] = 0;
        }
        Database::instance()->update('users', $data, ['id' => $this->id()]);
    }

    public function clearFailedLogins(): void
    {
        if ((int) ($this->row['failed_logins'] ?? 0) === 0 && ($this->row['locked_until'] ?? null) === null) {
            return;
        }
        Database::instance()->update(
            'users',
            ['failed_logins' => 0, 'locked_until' => null, 'updated_at' => time()],
            ['id' => $this->id()]
        );
    }

    public function changePassword(string $new): ?string
    {
        $problem = self::passwordProblem($new);
        if ($problem !== null) {
            return $problem;
        }
        Database::instance()->update(
            'users',
            ['password_hash' => Security::hashPassword($new), 'updated_at' => time()],
            ['id' => $this->id()]
        );
        // Every other device is signed out on a password change.
        Database::instance()->run('DELETE FROM sessions WHERE user_id = :u', ['u' => $this->id()]);
        return null;
    }

    public function id(): int
    {
        return (int) $this->row['id'];
    }

    public function email(): string
    {
        return (string) $this->row['email'];
    }

    public function displayName(): string
    {
        $name = trim((string) ($this->row['display_name'] ?? ''));
        if ($name !== '') {
            return $name;
        }
        return (string) strstr($this->email(), '@', true);
    }

    public function status(): string
    {
        return (string) ($this->row['status'] ?? 'active');
    }

    public function plan(): string
    {
        $plan = (string) ($this->row['plan'] ?? self::PLAN_FREE);
        $expires = $this->row['plan_expires_at'] ?? null;
        if ($plan !== self::PLAN_FREE && $expires !== null && (int) $expires < time()) {
            return self::PLAN_FREE;
        }
        return $plan;
    }

    public function isPaid(): bool
    {
        return $this->plan() !== self::PLAN_FREE;
    }

    public function createdAt(): int
    {
        return (int) $this->row['created_at'];
    }

    public function setPlan(string $plan, ?int $expiresAt = null): void
    {
        Database::instance()->update(
            'users',
            ['plan' => $plan, 'plan_expires_at' => $expiresAt, 'updated_at' => time()],
            ['id' => $this->id()]
        );
        $this->row['plan'] = $plan;
        $this->row['plan_expires_at'] = $expiresAt;
    }

    public function setDisplayName(string $name): void
    {
        $name = mb_substr(trim($name), 0, 80);
        Database::instance()->update(
            'users',
            ['display_name' => $name, 'updated_at' => time()],
            ['id' => $this->id()]
        );
        $this->row['display_name'] = $name;
    }
}
