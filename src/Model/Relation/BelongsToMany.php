<?php

declare(strict_types=1);

namespace Kode\Database\Model\Relation;

use Kode\Database\Model\Model;

/**
 * 多对多关联
 */
class BelongsToMany extends Relation
{
    protected string $table;
    protected ?string $foreignPivotKey;
    protected ?string $relatedPivotKey;
    protected string $parentKey;
    protected string $relatedKey;
    protected array $records = [];

    public function __construct(
        Model $parent,
        string $related,
        string $table,
        ?string $foreignPivotKey,
        ?string $relatedPivotKey
    ) {
        parent::__construct($parent, $related, $foreignPivotKey, $relatedPivotKey);
        $this->table = $table;
        $this->foreignPivotKey = $foreignPivotKey;
        $this->relatedPivotKey = $relatedPivotKey;
        $this->parentKey = $parent->getKeyName();
        $this->relatedKey = (new $related())->getKeyName();
    }

    public function getResults(): array
    {
        $sql = "SELECT r.* FROM {$this->related->table} r
                INNER JOIN {$this->table} t ON r.{$this->relatedKey} = t.{$this->relatedPivotKey}
                WHERE t.{$this->foreignPivotKey} = ?";

        $results = \Kode\Database\Db\Db::select($sql, [$this->parent->{$this->parentKey}]);

        $instances = [];
        foreach ($results as $result) {
            $instance = new $this->related();
            $instance->setAttributes($result);
            $instance->exists = true;
            $instances[] = $instance;
        }

        return $instances;
    }

    public function get(): array
    {
        return $this->getResults();
    }

    public function attach(mixed $id, ?array $attributes = []): void
    {
        $record = [
            $this->foreignPivotKey => $this->parent->{$this->parentKey},
            $this->relatedPivotKey => $id,
        ];

        if (!empty($attributes)) {
            $record = array_merge($record, $attributes);
        }

        $columns = array_keys($record);
        $values = array_values($record);
        $placeholders = implode(', ', array_fill(0, count($values), '?'));

        $sql = "INSERT INTO {$this->table} (" . implode(', ', $columns) . ") VALUES ({$placeholders})";
        \Kode\Database\Db\Db::insert($sql, $values);
    }

    public function detach(mixed $id = null): int
    {
        if ($id === null) {
            $sql = "DELETE FROM {$this->table} WHERE {$this->foreignPivotKey} = ?";
            return \Kode\Database\Db\Db::delete($sql, [$this->parent->{$this->parentKey}]);
        }

        $sql = "DELETE FROM {$this->table} WHERE {$this->foreignPivotKey} = ? AND {$this->relatedPivotKey} = ?";
        return \Kode\Database\Db\Db::delete($sql, [$this->parent->{$this->parentKey}, $id]);
    }

    public function sync(array $ids): void
    {
        $this->detach();
        foreach ($ids as $id) {
            $this->attach($id);
        }
    }

    public function toggle(array $ids): void
    {
        $existing = $this->getResults();
        $existingIds = array_map(fn($m) => $m->{$this->relatedKey}, $existing);

        foreach ($ids as $id) {
            if (in_array($id, $existingIds)) {
                $this->detach($id);
            } else {
                $this->attach($id);
            }
        }
    }

    public function updateExistingPivot(mixed $id, array $attributes): int
    {
        $sql = "UPDATE {$this->table} SET ";
        $sets = [];
        $values = [];

        foreach ($attributes as $key => $value) {
            $sets[] = "{$key} = ?";
            $values[] = $value;
        }

        $sql .= implode(', ', $sets);
        $sql .= " WHERE {$this->foreignPivotKey} = ? AND {$this->relatedPivotKey} = ?";
        $values[] = $this->parent->{$this->parentKey};
        $values[] = $id;

        return \Kode\Database\Db\Db::update($sql, $values);
    }
}
