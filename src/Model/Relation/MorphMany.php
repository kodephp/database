<?php

declare(strict_types=1);

namespace Kode\Database\Model\Relation;

use Kode\Database\Model\Model;

/**
 * 多态一对多关联
 */
class MorphMany extends Relation
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

    public function getResults(): array
    {
        $query = $this->query();
        $query->where($this->type, get_class($this->parent));
        $query->where($this->id, $this->parent->{$this->localKey});
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
