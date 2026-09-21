<?php
declare(strict_types=1);

namespace QRoute\Models;

use QRoute\Core\Database;
use QRoute\Services\RuleEngine;

final class Rule
{
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
        $row = Database::instance()->first('SELECT * FROM rules WHERE id = :id', ['id' => $id]);
        return $row === null ? null : new self($row);
    }

    public static function findForLink(int $id, int $linkId): ?self
    {
        $row = Database::instance()->first(
            'SELECT * FROM rules WHERE id = :id AND link_id = :l',
            ['id' => $id, 'l' => $linkId]
        );
        return $row === null ? null : new self($row);
    }

    /**
     * Raw rows in evaluation order. The redirect path uses this directly
     * rather than hydrating objects, to keep allocations down.
     *
     * @return list<array<string,mixed>>
     */
    public static function rowsForLink(int $linkId): array
    {
        return Database::instance()->all(
            'SELECT id, link_id, label, priority, conditions, target_url, is_active
             FROM rules WHERE link_id = :l ORDER BY priority ASC, id ASC',
            ['l' => $linkId]
        );
    }

    /** @return list<self> */
    public static function forLink(int $linkId): array
    {
        return array_map(
            static fn(array $r) => new self($r),
            Database::instance()->all(
                'SELECT * FROM rules WHERE link_id = :l ORDER BY priority ASC, id ASC',
                ['l' => $linkId]
            )
        );
    }

    public static function countForLink(int $linkId): int
    {
        return (int) Database::instance()->scalar(
            'SELECT COUNT(*) FROM rules WHERE link_id = :l',
            ['l' => $linkId]
        );
    }

    /** @param array<string,mixed> $conditions */
    public static function create(int $linkId, string $targetUrl, array $conditions, string $label = ''): self
    {
        $now = time();
        $priority = (int) Database::instance()->scalar(
            'SELECT COALESCE(MAX(priority), 0) + 10 FROM rules WHERE link_id = :l',
            ['l' => $linkId]
        );
        $id = Database::instance()->insert('rules', [
            'link_id'    => $linkId,
            'label'      => mb_substr(trim($label), 0, 120),
            'priority'   => $priority,
            'conditions' => json_encode($conditions, JSON_UNESCAPED_SLASHES) ?: '{}',
            'target_url' => $targetUrl,
            'is_active'  => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $rule = self::find($id);
        if ($rule === null) {
            throw new \RuntimeException('Rule creation failed.');
        }
        return $rule;
    }

    /** @param array<string,mixed> $data */
    public function update(array $data): void
    {
        $data['updated_at'] = time();
        Database::instance()->update('rules', $data, ['id' => $this->id()]);
        $this->row = array_merge($this->row, $data);
    }

    public function delete(): void
    {
        Database::instance()->run('DELETE FROM rules WHERE id = :id', ['id' => $this->id()]);
    }

    /**
     * Reorders rules within a link. Priorities are rewritten in steps of ten
     * so a later insert can slot between two without a full renumber.
     *
     * @param list<int> $orderedIds
     */
    public static function reorder(int $linkId, array $orderedIds): void
    {
        $db = Database::instance();
        $db->transaction(static function () use ($db, $linkId, $orderedIds): void {
            $priority = 10;
            foreach ($orderedIds as $id) {
                $db->run(
                    'UPDATE rules SET priority = :p, updated_at = :t WHERE id = :id AND link_id = :l',
                    ['p' => $priority, 't' => time(), 'id' => (int) $id, 'l' => $linkId]
                );
                $priority += 10;
            }
        });
    }

    public function id(): int
    {
        return (int) $this->row['id'];
    }

    public function linkId(): int
    {
        return (int) $this->row['link_id'];
    }

    public function label(): string
    {
        $l = trim((string) ($this->row['label'] ?? ''));
        return $l !== '' ? $l : $this->describe();
    }

    public function targetUrl(): string
    {
        return (string) $this->row['target_url'];
    }

    public function isActive(): bool
    {
        return (int) ($this->row['is_active'] ?? 1) === 1;
    }

    public function priority(): int
    {
        return (int) ($this->row['priority'] ?? 0);
    }

    public function hits(): int
    {
        return (int) ($this->row['hits'] ?? 0);
    }

    /** @return array<string,mixed> */
    public function conditions(): array
    {
        return RuleEngine::decode((string) ($this->row['conditions'] ?? '{}'));
    }

    public function describe(): string
    {
        return RuleEngine::describe($this->conditions());
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'id'         => $this->id(),
            'label'      => $this->label(),
            'priority'   => $this->priority(),
            'conditions' => $this->conditions(),
            'target_url' => $this->targetUrl(),
            'is_active'  => $this->isActive(),
            'hits'       => $this->hits(),
            'describes'  => $this->describe(),
        ];
    }
}
