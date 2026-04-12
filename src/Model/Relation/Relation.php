<?php

declare(strict_types=1);

namespace Kode\Database\Model\Relation;

use Kode\Database\Model\Model;

/**
 * 一对一关联
 */
abstract class Relation
{
    protected Model $parent;
    protected Model $related;
    protected ?string $foreignKey;
    protected ?string $localKey;

    public function __construct(Model $parent, string $related, ?string $foreignKey, ?string $localKey)
    {
        $this->parent = $parent;
        $this->related = new $related();
        $this->foreignKey = $foreignKey;
        $this->localKey = $localKey;
    }

    abstract public function getResults(): ?Model;

    public function get(): mixed
    {
        return $this->getResults();
    }

    public function first(): ?Model
    {
        return $this->getResults();
    }

    public function find(mixed $id): ?Model
    {
        return $this->query()->find($id);
    }

    public function findOrFail(mixed $id): ?Model
    {
        $result = $this->find($id);
        if ($result === null) {
            throw \Kode\Database\Exception\ModelNotFoundException::notFound(get_class($this->related));
        }
        return $result;
    }

    public function firstOrCreate(array $attributes, ?array $values = null): Model
    {
        $instance = $this->query()->where($attributes)->first();

        if ($instance === null) {
            $instance = new $this->related();
            $instance->fill(array_merge($attributes, $values ?? []));
            $instance->save();
        }

        return $instance;
    }

    public function updateOrCreate(array $attributes, array $values = []): Model
    {
        $instance = $this->query()->where($attributes)->first();

        if ($instance === null) {
            $instance = new $this->related();
            $instance->fill(array_merge($attributes, $values));
            $instance->save();
        } else {
            $instance->fill($values);
            $instance->save();
        }

        return $instance;
    }

    protected function query(): \Kode\Database\Query\QueryBuilder
    {
        return $this->related->query();
    }
}
