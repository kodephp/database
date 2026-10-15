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

    abstract public function getResults(): Model|array|null;

    public function get(): mixed
    {
        return $this->getResults();
    }

    public function first(): ?Model
    {
        $results = $this->getResults();

        if (is_array($results)) {
            $first = $results[0] ?? null;
            return $first instanceof Model ? $first : null;
        }

        return $results;
    }

    public function getQuery(): \Kode\Database\Query\QueryBuilder
    {
        return $this->query();
    }

    public function getRelated(): Model
    {
        return $this->related;
    }

    public function getForeignKey(): ?string
    {
        return $this->foreignKey;
    }

    public function getLocalKey(): ?string
    {
        return $this->localKey;
    }

    /**
     * 数据库行 → 关联模型实例（setAttributes + exists=true + original 同步）
     */
    protected function hydrate(array $row): Model
    {
        return $this->related->newFromBuilder($row);
    }

    public function find(mixed $id): ?Model
    {
        $row = $this->query()->find($id);

        return $row !== null ? $this->hydrate($row) : null;
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
        $row = $this->query()->where($attributes)->first();

        if ($row !== null) {
            return $this->hydrate($row);
        }

        $instance = new $this->related();
        $instance->fill(array_merge($attributes, $values ?? []));
        $instance->save();

        return $instance;
    }

    public function updateOrCreate(array $attributes, array $values = []): Model
    {
        $row = $this->query()->where($attributes)->first();

        if ($row !== null) {
            $instance = $this->hydrate($row);
            $instance->fill($values);
            $instance->save();

            return $instance;
        }

        $instance = new $this->related();
        $instance->fill(array_merge($attributes, $values));
        $instance->save();

        return $instance;
    }

    protected function query(): \Kode\Database\Query\QueryBuilder
    {
        return $this->related->query();
    }
}
