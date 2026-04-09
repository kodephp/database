<?php

declare(strict_types=1);

namespace Kode\Database\Model\Relation;

use Kode\Database\Model\Model;

/**
 * 多态一对一关联
 */
class MorphOne extends Relation
{
    protected string $name;
    protected string $type;
    protected string $id;
    protected ?string $localKey;

    public function __construct(
        Model $parent,
        string $related,
        string $name,
        string $type,
        string $id,
        ?string $localKey
    ) {
        parent::__construct($parent, $related, $id, $localKey);
        $this->name = $name;
        $this->type = $type;
        $this->id = $id;
        $this->localKey = $localKey ?? $parent->getKeyName();
    }

    public function getResults(): ?Model
    {
        $query = $this->query();
        $query->where($this->type, get_class($this->parent));
        $query->where($this->id, $this->parent->{$this->localKey});
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
