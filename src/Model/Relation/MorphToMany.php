<?php

declare(strict_types=1);

namespace Kode\Database\Model\Relation;

use Kode\Database\Model\Model;

/**
 * 多态多对多关联
 */
class MorphToMany extends BelongsToMany
{
    protected string $name;

    public function __construct(
        Model $parent,
        string $related,
        string $name,
        string $table,
        ?string $foreignPivotKey,
        ?string $relatedPivotKey
    ) {
        parent::__construct($parent, $related, $table, $foreignPivotKey, $relatedPivotKey);
        $this->name = $name;
    }

    public function getResults(): array
    {
        $sql = "SELECT r.* FROM {$this->related->table} r
                INNER JOIN {$this->table} t ON r.{$this->relatedKey} = t.{$this->relatedPivotKey}
                WHERE t.{$this->foreignPivotKey} = ? AND t.{$this->relatedPivotKey} LIKE ?";

        $typePattern = '%' . get_class($this->parent) . '%';
        $results = \Kode\Database\Db\Db::select($sql, [$this->parent->{$this->parentKey}, $typePattern]);

        $instances = [];
        foreach ($results as $result) {
            $instance = new $this->related();
            $instance->setAttributes($result);
            $instance->exists = true;
            $instances[] = $instance;
        }

        return $instances;
    }
}
