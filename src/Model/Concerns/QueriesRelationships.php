<?php

declare(strict_types=1);

namespace Kode\Database\Model\Concerns;

use Kode\Database\Db\Db;

/**
 * 关联查询 Trait
 * 支持一对一、一对多、多对多、多态关联
 */
trait QueriesRelationships
{
    /**
     * 预加载关联
     */
    protected array $eagerLoad = [];

    /**
     * 设置预加载关联
     */
    public function with(string|array $relations): static
    {
        if (is_string($relations)) {
            $relations = array_map('trim', explode(',', $relations));
        }
        $this->eagerLoad = array_merge($this->eagerLoad, $relations);
        return $this;
    }

    /**
     * 执行预加载
     */
    protected function eagerLoadRelations(array $models): array
    {
        if (empty($this->eagerLoad)) {
            return $models;
        }

        foreach ($this->eagerLoad as $relation) {
            $this->loadRelation($models, $relation);
        }

        return $models;
    }

    /**
     * 加载单个关联
     */
    protected function loadRelation(array $models, string $relation): void
    {
        $relationMethod = camel_case($relation);

        if (!method_exists($this, $relationMethod)) {
            return;
        }

        $relationInstance = $this->$relationMethod();

        if ($relationInstance instanceof \Kode\Database\Model\Relation\HasOne ||
            $relationInstance instanceof \Kode\Database\Model\Relation\HasMany) {
            $this->loadHasRelation($models, $relationInstance, $relation);
        } elseif ($relationInstance instanceof \Kode\Database\Model\Relation\BelongsTo) {
            $this->loadBelongsToRelation($models, $relationInstance, $relation);
        } elseif ($relationInstance instanceof \Kode\Database\Model\Relation\BelongsToMany) {
            $this->loadBelongsToManyRelation($models, $relationInstance, $relation);
        }
    }

    /**
     * 加载 HasOne/HasMany 关联
     */
    protected function loadHasRelation(array $models, $relation, string $name): void
    {
        $related = $relation->getRelated();
        $foreignKey = $relation->getForeignKey();
        $localKey = $relation->getLocalKey();

        $keys = array_filter(array_unique(array_column($models, $localKey)));

        if (empty($keys)) {
            return;
        }

        $query = new \Kode\Database\Query\QueryBuilder(Db::getConnection());
        $query->table($related->getTable())
              ->whereIn($foreignKey, $keys);

        $results = $query->get();
        $grouped = [];

        foreach ($results as $result) {
            $grouped[$result[$foreignKey]][] = $result;
        }

        foreach ($models as $model) {
            $key = $model->{$localKey};
            $items = $grouped[$key] ?? [];

            if ($relation instanceof \Kode\Database\Model\Relation\HasOne) {
                $model->setRelation($name, $items[0] ?? null);
            } else {
                $model->setRelation($name, $items);
            }
        }
    }

    /**
     * 加载 BelongsTo 关联
     */
    protected function loadBelongsToRelation(array $models, $relation, string $name): void
    {
        $related = $relation->getRelated();
        $ownerKey = $relation->getOwnerKey();
        $foreignKey = $relation->getForeignKey();

        $keys = array_filter(array_unique(array_column($models, $foreignKey)));

        if (empty($keys)) {
            return;
        }

        $query = new \Kode\Database\Query\QueryBuilder(Db::getConnection());
        $query->table($related->getTable())
              ->whereIn($ownerKey, $keys);

        $results = $query->get();
        $indexed = [];

        foreach ($results as $result) {
            $indexed[$result[$ownerKey]] = $result;
        }

        foreach ($models as $model) {
            $key = $model->{$foreignKey};
            $model->setRelation($name, $indexed[$key] ?? null);
        }
    }

    /**
     * 加载 BelongsToMany 关联
     */
    protected function loadBelongsToManyRelation(array $models, $relation, string $name): void
    {
        $related = $relation->getRelated();
        $pivotTable = $relation->getTable();
        $foreignPivotKey = $relation->getForeignPivotKey();
        $relatedPivotKey = $relation->getRelatedPivotKey();

        $keys = array_filter(array_unique(array_column($models, $this->getKeyName())));

        if (empty($keys)) {
            return;
        }

        $sql = sprintf(
            'SELECT r.*, p.%s as pivot_%s FROM %s p INNER JOIN %s r ON p.%s = r.%s WHERE p.%s IN (%s)',
            $relatedPivotKey,
            $foreignPivotKey,
            $pivotTable,
            $related->getTable(),
            $relatedPivotKey,
            $related->getPrimaryKey(),
            $foreignPivotKey,
            implode(',', array_fill(0, count($keys), '?'))
        );

        $results = Db::select($sql, $keys);
        $grouped = [];

        foreach ($results as $result) {
            $pivotKey = $result['pivot_' . $foreignPivotKey] ?? null;
            if ($pivotKey !== null) {
                unset($result['pivot_' . $foreignPivotKey]);
                $grouped[$pivotKey][] = $result;
            }
        }

        foreach ($models as $model) {
            $key = $model->{$this->getKeyName()};
            $model->setRelation($name, $grouped[$key] ?? []);
        }
    }

    /**
     * 一对一（正向）
     */
    public function hasOne(string $related, ?string $foreignKey = null, ?string $localKey = null): \Kode\Database\Model\Relation\HasOne
    {
        $instance = $this->newInstance();
        $foreignKey = $foreignKey ?? $this->getForeignKey();
        $localKey = $localKey ?? $this->getKeyName();

        return new \Kode\Database\Model\Relation\HasOne(
            $instance,
            $related,
            $foreignKey,
            $localKey
        );
    }

    /**
     * 一对多
     */
    public function hasMany(string $related, ?string $foreignKey = null, ?string $localKey = null): \Kode\Database\Model\Relation\HasMany
    {
        $instance = $this->newInstance();
        $foreignKey = $foreignKey ?? $this->getForeignKey();
        $localKey = $localKey ?? $this->getKeyName();

        return new \Kode\Database\Model\Relation\HasMany(
            $instance,
            $related,
            $foreignKey,
            $localKey
        );
    }

    /**
     * 属于（反向一对一）
     */
    public function belongsTo(string $related, ?string $foreignKey = null, ?string $ownerKey = null): \Kode\Database\Model\Relation\BelongsTo
    {
        $instance = $this->newInstance();
        $foreignKey = $foreignKey ?? $this->getForeignKey();
        $ownerKey = $ownerKey ?? $instance->getKeyName();

        return new \Kode\Database\Model\Relation\BelongsTo(
            $instance,
            $related,
            $foreignKey,
            $ownerKey
        );
    }

    /**
     * 多对多
     */
    public function belongsToMany(string $related, ?string $table = null, ?string $foreignPivotKey = null, ?string $relatedPivotKey = null): \Kode\Database\Model\Relation\BelongsToMany
    {
        $instance = $this->newInstance();
        $table = $table ?? $this->joiningTable();
        $foreignPivotKey = $foreignPivotKey ?? $this->getForeignKey();
        $relatedPivotKey = $relatedPivotKey ?? $instance->getForeignKey();

        return new \Kode\Database\Model\Relation\BelongsToMany(
            $instance,
            $related,
            $table,
            $foreignPivotKey,
            $relatedPivotKey
        );
    }

    /**
     * 多态一对一
     */
    public function morphOne(string $related, string $name, ?string $type = null, ?string $id = null, ?string $localKey = null): \Kode\Database\Model\Relation\MorphOne
    {
        $instance = $this->newInstance();
        [$type, $id] = $this->getMorphPair($name, $type, $id);
        $localKey = $localKey ?? $this->getKeyName();

        return new \Kode\Database\Model\Relation\MorphOne(
            $instance,
            $related,
            $name,
            $type,
            $id,
            $localKey
        );
    }

    /**
     * 多态一对多
     */
    public function morphMany(string $related, string $name, ?string $type = null, ?string $id = null, ?string $localKey = null): \Kode\Database\Model\Relation\MorphMany
    {
        $instance = $this->newInstance();
        [$type, $id] = $this->getMorphPair($name, $type, $id);
        $localKey = $localKey ?? $this->getKeyName();

        return new \Kode\Database\Model\Relation\MorphMany(
            $instance,
            $related,
            $name,
            $type,
            $id,
            $localKey
        );
    }

    /**
     * 多态
     */
    public function morphTo(string $name): \Kode\Database\Model\Relation\MorphTo
    {
        return new \Kode\Database\Model\Relation\MorphTo(
            $this->newInstance(),
            $name
        );
    }

    /**
     * 多态多对多（正向）
     */
    public function morphToMany(string $related, string $name, ?string $table = null, ?string $foreignPivotKey = null, ?string $relatedPivotKey = null): \Kode\Database\Model\Relation\MorphToMany
    {
        $instance = $this->newInstance();
        $table = $table ?? $this->joiningTable();
        $foreignPivotKey = $foreignPivotKey ?? $name . '_id';
        $relatedPivotKey = $relatedPivotKey ?? $name . '_type';

        return new \Kode\Database\Model\Relation\MorphToMany(
            $instance,
            $related,
            $name,
            $table,
            $foreignPivotKey,
            $relatedPivotKey
        );
    }

    /**
     * 多态多对多（反向）
     */
    public function morphedByMany(string $related, string $name, ?string $table = null, ?string $foreignPivotKey = null, ?string $relatedPivotKey = null): \Kode\Database\Model\Relation\MorphToMany
    {
        $relatedPivotKey = $relatedPivotKey ?? $this->getForeignKey();
        $foreignPivotKey = $foreignPivotKey ?? $name . '_id';

        return $this->morphToMany($related, $name, $table, $foreignPivotKey, $relatedPivotKey);
    }

    /**
     * 获取多态类型和ID
     */
    protected function getMorphPair(string $name, ?string $type, ?string $id): array
    {
        $type = $type ?? $name . '_type';
        $id = $id ?? $name . '_id';
        return [$type, $id];
    }

    /**
     * 获取关联表名
     */
    protected function joiningTable(): string
    {
        $segments = [basename(str_replace('\\', '/', static::class)), $this->table];
        sort($segments);
        return strtolower(implode('_', $segments));
    }

    /**
     * 获取外键名
     */
    public function getForeignKey(): string
    {
        $className = basename(str_replace('\\', '/', static::class));
        return strtolower(lcfirst($className)) . '_id';
    }

    /**
     * 创建新实例
     */
    protected function newInstance(): static
    {
        return new static();
    }

    /**
     * 设置关联数据
     */
    public function setRelation(string $name, mixed $value): void
    {
        $this->relations[$name] = $value;
    }

    /**
     * 获取关联数据
     */
    public function getRelation(string $name): mixed
    {
        return $this->relations[$name] ?? null;
    }

    /**
     * 关联是否存在
     */
    public function hasRelation(string $name): bool
    {
        return isset($this->relations[$name]);
    }
}
