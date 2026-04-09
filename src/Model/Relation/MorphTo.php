<?php

declare(strict_types=1);

namespace Kode\Database\Model\Relation;

use Kode\Database\Model\Model;

/**
 * 多态关联基类
 */
class MorphTo extends Relation
{
    protected string $name;

    public function __construct(Model $parent, string $name)
    {
        $this->parent = $parent;
        $this->name = $name;
    }

    public function getResults(): ?Model
    {
        $typeField = $this->name . '_type';
        $idField = $this->name . '_id';

        if (!$this->parent->offsetExists($typeField) || !$this->parent->offsetExists($idField)) {
            return null;
        }

        $type = $this->parent->{$typeField};
        $id = $this->parent->{$idField};

        if (!$type || !$id) {
            return null;
        }

        $instance = new $type();
        $result = $instance->query()->find($id);

        return $result;
    }
}
