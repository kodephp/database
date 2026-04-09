<?php

declare(strict_types=1);

namespace Kode\Database\Model\Relation;

use Kode\Database\Model\Model;

/**
 * 一对多关联
 */
class HasMany extends Relation
{
    public function getResults(): array
    {
        $query = $this->query();
        $query->where($this->foreignKey, $this->parent->{$this->localKey});
        $results = $query->get();

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

    public function count(): int
    {
        $query = $this->query();
        $query->where($this->foreignKey, $this->parent->{$this->localKey});
        return $query->count();
    }

    public function of(mixed $id): array
    {
        $query = $this->query();
        $query->where($this->foreignKey, $id);
        $results = $query->get();

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
