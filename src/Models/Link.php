<?php
declare(strict_types=1);

namespace QRoute\Models;

use QRoute\Core\Database;
use QRoute\Core\Security;
use QRoute\Services\QrRenderer;

final class Link
{
    /**
     * Slug alphabet with the visually ambiguous characters removed.
     * Someone squinting at a printed code and typing the URL by hand should
     * never have to guess between 0 and O, or 1 and l.
     */
    private const ALPHABET = '23456789abcdefghijkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ';
    private const SLUG_LENGTH = 7;

    /** Paths the application itself owns, which can never become a slug. */
    public const RESERVED = [
        'app', 'api', 'admin', 'login', 'logout', 'register', 'signup', 'signin',
        'dashboard', 'settings', 'account', 'billing', 'pricing', 'plans',
        'assets', 'static', 'public', 'favicon', 'robots', 'sitemap', 'health',
        'qr', 'r', 'go', 'l', 'i', 's', 'help', 'docs', 'support', 'legal',
        'privacy', 'terms', 'about', 'contact', 'blog', 'status', 'preview',
        'new', 'edit', 'delete', 'create', 'upgrade', 'well-known',
    ];

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
        $row = Database::instance()->first('SELECT * FROM links WHERE id = :id', ['id' => $id]);
        return $row === null ? null : new self($row);
    }

    public static function findForUser(int $id, int $userId): ?self
    {
        $row = Database::instance()->first(
            'SELECT * FROM links WHERE id = :id AND user_id = :u',
            ['id' => $id, 'u' => $userId]
        );
        return $row === null ? null : new self($row);
    }

    /** The redirect hot path: one indexed lookup by slug. */
    public static function findBySlug(string $slug): ?self
    {
        $row = Database::instance()->first(
            'SELECT * FROM links WHERE slug = :s AND archived_at IS NULL',
            ['s' => $slug]
        );
        return $row === null ? null : new self($row);
    }

    /** @return list<self> */
    public static function forUser(int $userId, bool $includeArchived = false): array
    {
        $sql = 'SELECT * FROM links WHERE user_id = :u'
            . ($includeArchived ? '' : ' AND archived_at IS NULL')
            . ' ORDER BY created_at DESC';
        return array_map(
            static fn(array $r) => new self($r),
            Database::instance()->all($sql, ['u' => $userId])
        );
    }

    public static function countForUser(int $userId): int
    {
        return (int) Database::instance()->scalar(
            'SELECT COUNT(*) FROM links WHERE user_id = :u AND archived_at IS NULL',
            ['u' => $userId]
        );
    }

    public static function slugExists(string $slug): bool
    {
        return Database::instance()->scalar(
            'SELECT 1 FROM links WHERE slug = :s',
            ['s' => $slug]
        ) !== null;
    }

    public static function generateSlug(): string
    {
        $max = strlen(self::ALPHABET) - 1;
        for ($attempt = 0; $attempt < 12; $attempt++) {
            $slug = '';
            for ($i = 0; $i < self::SLUG_LENGTH; $i++) {
                $slug .= self::ALPHABET[random_int(0, $max)];
            }
            if (!self::slugExists($slug) && !self::isReserved($slug)) {
                return $slug;
            }
        }
        throw new \RuntimeException('Could not allocate a unique slug.');
    }

    public static function isReserved(string $slug): bool
    {
        return in_array(strtolower($slug), self::RESERVED, true);
    }

    /**
     * @return array{ok:bool,slug:string,error:string}
     */
    public static function validateCustomSlug(string $slug): array
    {
        $slug = trim($slug);
        if ($slug === '') {
            return ['ok' => false, 'slug' => '', 'error' => 'Custom link cannot be empty.'];
        }
        if (strlen($slug) < 3 || strlen($slug) > 32) {
            return ['ok' => false, 'slug' => '', 'error' => 'Custom link must be 3 to 32 characters.'];
        }
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9_-]*[A-Za-z0-9]$/', $slug) !== 1) {
            return ['ok' => false, 'slug' => '', 'error' => 'Use only letters, numbers, hyphens and underscores.'];
        }
        if (self::isReserved($slug)) {
            return ['ok' => false, 'slug' => '', 'error' => 'That name is reserved. Please pick another.'];
        }
        if (self::slugExists($slug)) {
            return ['ok' => false, 'slug' => '', 'error' => 'That name is already taken.'];
        }
        return ['ok' => true, 'slug' => $slug, 'error' => ''];
    }

    /**
     * @param array<string,mixed> $data
     */
    public static function create(int $userId, string $slug, string $defaultUrl, array $data = []): self
    {
        $now = time();
        $id = Database::instance()->insert('links', [
            'user_id'     => $userId,
            'slug'        => $slug,
            'title'       => mb_substr(trim((string) ($data['title'] ?? '')), 0, 120),
            'default_url' => $defaultUrl,
            'is_active'   => 1,
            'style_json'  => isset($data['style']) ? json_encode($data['style']) : null,
            'expires_at'  => $data['expires_at'] ?? null,
            'expired_url' => $data['expired_url'] ?? null,
            'created_at'  => $now,
            'updated_at'  => $now,
        ]);
        $link = self::find($id);
        if ($link === null) {
            throw new \RuntimeException('Link creation failed.');
        }
        return $link;
    }

    /** @param array<string,mixed> $data */
    public function update(array $data): void
    {
        $data['updated_at'] = time();
        Database::instance()->update('links', $data, ['id' => $this->id()]);
        $this->row = array_merge($this->row, $data);
    }

    public function archive(): void
    {
        $this->update(['archived_at' => time()]);
    }

    public function restore(): void
    {
        $this->update(['archived_at' => null]);
    }

    /** Permanently removes the link and everything attached to it. */
    public function delete(): void
    {
        $db = Database::instance();
        $db->transaction(function () use ($db): void {
            $db->run('DELETE FROM rules WHERE link_id = :l', ['l' => $this->id()]);
            $db->run('DELETE FROM scans WHERE link_id = :l', ['l' => $this->id()]);
            $db->run('DELETE FROM scan_daily WHERE link_id = :l', ['l' => $this->id()]);
            $db->run('DELETE FROM links WHERE id = :l', ['l' => $this->id()]);
        });
    }

    public function id(): int
    {
        return (int) $this->row['id'];
    }

    public function userId(): int
    {
        return (int) $this->row['user_id'];
    }

    public function slug(): string
    {
        return (string) $this->row['slug'];
    }

    public function title(): string
    {
        $t = trim((string) ($this->row['title'] ?? ''));
        if ($t !== '') {
            return $t;
        }
        $host = parse_url($this->defaultUrl(), PHP_URL_HOST);
        return is_string($host) ? $host : $this->slug();
    }

    public function defaultUrl(): string
    {
        return (string) $this->row['default_url'];
    }

    public function isActive(): bool
    {
        return (int) ($this->row['is_active'] ?? 1) === 1;
    }

    public function scanCount(): int
    {
        return (int) ($this->row['scan_count'] ?? 0);
    }

    public function lastScanAt(): ?int
    {
        $v = $this->row['last_scan_at'] ?? null;
        return $v === null ? null : (int) $v;
    }

    public function createdAt(): int
    {
        return (int) $this->row['created_at'];
    }

    public function archivedAt(): ?int
    {
        $v = $this->row['archived_at'] ?? null;
        return $v === null ? null : (int) $v;
    }

    public function expiresAt(): ?int
    {
        $v = $this->row['expires_at'] ?? null;
        return $v === null ? null : (int) $v;
    }

    public function expiredUrl(): ?string
    {
        $v = $this->row['expired_url'] ?? null;
        return $v === null || $v === '' ? null : (string) $v;
    }

    public function hasExpired(?int $now = null): bool
    {
        $exp = $this->expiresAt();
        return $exp !== null && ($now ?? time()) > $exp;
    }

    public function passwordHash(): ?string
    {
        $v = $this->row['password_hash'] ?? null;
        return $v === null || $v === '' ? null : (string) $v;
    }

    public function isPasswordProtected(): bool
    {
        return $this->passwordHash() !== null;
    }

    public function checkPassword(string $password): bool
    {
        $hash = $this->passwordHash();
        return $hash !== null && Security::verifyPassword($password, $hash);
    }

    /** @return array<string,mixed> */
    public function style(): array
    {
        $raw = $this->row['style_json'] ?? null;
        if (!is_string($raw) || $raw === '') {
            return [];
        }
        $data = json_decode($raw, true, 6, JSON_INVALID_UTF8_SUBSTITUTE);
        return is_array($data) ? $data : [];
    }

    /** Style with defaults applied, safe to pass to the renderer. */
    public function renderStyle(): array
    {
        $style = $this->style();
        $ecc = strtoupper((string) ($style['ecc'] ?? 'Q'));

        return [
            'dark'  => QrRenderer::colour((string) ($style['dark'] ?? ''), QrRenderer::DEFAULT_DARK),
            'light' => QrRenderer::colour((string) ($style['light'] ?? ''), QrRenderer::DEFAULT_LIGHT),
            'shape' => ($style['shape'] ?? 'square') === 'dot' ? 'dot' : 'square',
            // Quartile by default: a printed code lives on a surface that
            // gets smudged, folded and photographed at an angle.
            'ecc'   => in_array($ecc, ['L', 'M', 'Q', 'H'], true) ? $ecc : 'Q',
        ];
    }

    public function shortUrl(string $baseUrl): string
    {
        return rtrim($baseUrl, '/') . '/' . $this->slug();
    }

    /**
     * Records a scan. Kept to a single UPDATE plus a single INSERT so the
     * redirect stays fast even under load.
     */
    public function recordScan(int $ruleId = 0): void
    {
        Database::instance()->run(
            'UPDATE links SET scan_count = scan_count + 1, last_scan_at = :t WHERE id = :id',
            ['t' => time(), 'id' => $this->id()]
        );
        $this->row['scan_count'] = $this->scanCount() + 1;
        if ($ruleId > 0) {
            Database::instance()->run(
                'UPDATE rules SET hits = hits + 1 WHERE id = :id',
                ['id' => $ruleId]
            );
        }
    }

    /** @return array<string,mixed> */
    public function toArray(string $baseUrl): array
    {
        return [
            'id'           => $this->id(),
            'slug'         => $this->slug(),
            'title'        => $this->title(),
            'short_url'    => $this->shortUrl($baseUrl),
            'default_url'  => $this->defaultUrl(),
            'is_active'    => $this->isActive(),
            'scan_count'   => $this->scanCount(),
            'last_scan_at' => $this->lastScanAt(),
            'expires_at'   => $this->expiresAt(),
            'created_at'   => $this->createdAt(),
            'archived'     => $this->archivedAt() !== null,
        ];
    }
}
