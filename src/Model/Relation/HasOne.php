<?php

declare(strict_types=1);

namespace Kode\Database\Model\Relation;

use Kode\Database\Model\Model;

/**
 * 一对一关联（正向）
 */
class HasOne extends Relation
{
    public function getResults(): ?Model
    {
        $query = $this->query();
        $query->where($this->foreignKey, $this->parent->{$this->localKey});
        $result = $query->first();

        if ($result) {
            $instance = new $this->related();
            $instance->setAttributes($result);
            $instance->exists = true;
            return $instance;
        }

        return null;
    }

    public function of(mixed $id): ?Model
    {
        $query = $this->query();
        $query->where($this->foreignKey, $id);
        $result = $query->first();

        if ($result) {
            $instance = new $this->related();
            $instance->setAttributes($result);
            $instance->exists = true;
            return $instance;
        }

        return null;
    }
}
