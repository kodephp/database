<?php

declare(strict_types=1);

namespace Kode\Database\Model\Concerns;

use Kode\Database\Db\Db;
use Kode\Database\Model\Model;
use Kode\Database\Support\Str;

/**
 * 关联查询 Trait
 * 支持一对一、一对多、多对多、多态关联、预加载、关联计数
 */
trait QueriesRelationships
{
    /** @var array 预加载关联 */
    protected array $eagerLoad = [];

    /** @var array 关联计数 */
    protected array $withCount = [];

    /** @var array 关联条件 */
    protected array $hasConstraints = [];

    /**
     * 实例态关联 DSL 派发器：with/withCount/has/whereHas/orWhereHas/doesNotHave/withWhereHas
     *
     * 这七个入口刻意不注册为真实方法：PHP 不允许同名方法同时是静态与实例，
     * 而 `User::with(...)`（静态入口）与 `->withCount(...)`（实例续链）两个形态都必须成立；
     * has 若留作真实静态方法，实例续链 `->has(...)` 会在内部另起原型，把链上 with/withCount 状态全部丢弃。
     * 唯一同时满足两者的形态是双层 magic —— Model::__callStatic 建原型实例 → Model::__call → 本方法。
     * 返回 static（查询态续链）或 bool（has 的数组存在性形态）。
     *
     * @param array $arguments 原始调用参数（保留调用侧形态，不做强类型收窄）
     * @return static|bool
     */
    protected function applyEagerDsl(string $method, array $arguments): static|bool
    {
        switch ($method) {
            case 'with':
                // User::with('profile,posts')
                $relations = $arguments[0] ?? '';
                if (is_string($relations)) {
                    $relations = array_map('trim', explode(',', $relations));
                }
                $this->eagerLoad = array_merge($this->eagerLoad, (array) $relations);
                return $this;

            case 'withCount':
                // User::withCount('posts') / User::withCount(['posts' => fn ($q) => ...])
                $relations = $arguments[0] ?? '';
                $callback = $arguments[1] ?? null;
                if (is_string($relations)) {
                    $relations = [$relations];
                }

                foreach ((array) $relations as $key => $value) {
                    if (is_string($value)) {
                        $this->withCount[] = [
                            'relation' => $value,
                            'callback' => $callback instanceof \Closure ? $callback : null,
                        ];
                    } else {
                        $this->withCount[] = [
                            'relation' => (string) $key,
                            'callback' => $value instanceof \Closure ? $value : ($callback instanceof \Closure ? $callback : null),
                        ];
                    }
                }
                return $this;

            case 'has':
                // User::has('posts', '>=', 3) 关联筛选（查询态）；User::has(['email' => 'x']) 记录存在性（bool）
                if (is_array($arguments[0] ?? null)) {
                    return static::checkExists($arguments[0]);
                }
                $this->hasConstraints[(string) ($arguments[0] ?? '')] = [
                    'operator' => (string) ($arguments[1] ?? '>='),
                    'count' => (int) ($arguments[2] ?? 1),
                ];
                return $this;

            case 'whereHas':
                // User::whereHas('posts', fn ($q) => $q->where('status', 1))
                $this->hasConstraints[(string) ($arguments[0] ?? '')] = [
                    'callback' => $arguments[1] ?? null,
                ];
                return $this;

            case 'orWhereHas':
                $this->hasConstraints[(string) ($arguments[0] ?? '')] = [
                    'callback' => $arguments[1] ?? null,
                    'or' => true,
                ];
                return $this;

            case 'doesNotHave':
                // User::doesNotHave('posts')
                $this->hasConstraints[(string) ($arguments[0] ?? '')] = [
                    'operator' => '=',
                    'count' => 0,
                ];
                return $this;

            case 'withWhereHas':
                // User::withWhereHas('posts', fn ($q) => ...) —— 预加载 + 关联条件一步到位
                $relations = $arguments[0] ?? '';
                $callback = $arguments[1] ?? null;
                if (is_string($relations)) {
                    $relations = array_map('trim', explode(',', $relations));
                }
                $this->eagerLoad = array_merge($this->eagerLoad, (array) $relations);

                if ($callback instanceof \Closure) {
                    // 与 whereHas 同形（['callback' => ...]）：applyHasConstraint 只收数组约束，
                    // 直接存裸 Closure 会在 get() 处触发 TypeError。
                    foreach ($relations as $name) {
                        if (is_string($name)) {
                            $this->hasConstraints[$name] = ['callback' => $callback];
                        }
                    }
                }
                return $this;

            default:
                throw new \BadMethodCallException(sprintf(
                    'Call to undefined method %s::%s()',
                    static::class,
                    $method,
                ));
        }
    }

    /**
     * 延迟预加载
     *
     * @param string|array $relations 关联名
     * @return $this
     * @example $user->load('profile,posts')
     */
    public function load(string|array $relations): static
    {
        if (is_string($relations)) {
            $relations = array_map('trim', explode(',', $relations));
        }

        foreach ($relations as $relation) {
            if (method_exists($this, $relation)) {
                $result = $this->$relation();

                if ($result instanceof \Kode\Database\Model\Relation\Relation) {
                    $this->relations[$relation] = $result->get();
                }
            }
        }

        return $this;
    }

    /**
     * 延迟预加载（带条件）
     *
     * @param string $relation 关联名
     * @param \Closure $callback 回调
     * @return $this
     */
    public function loadWhere(string $relation, \Closure $callback): static
    {
        if (method_exists($this, $relation)) {
            $result = $this->$relation();
            if ($result instanceof \Kode\Database\Model\Relation\Relation) {
                $callback($result->getQuery());
                $this->relations[$relation] = $result->get();
            }
        }

        return $this;
    }

    /**
     * 合并预加载
     *
     * @param string|array $relations 关联名
     * @return static
     */
    public function mergeLoad(string|array $relations): static
    {
        if (is_string($relations)) {
            $relations = array_map('trim', explode(',', $relations));
        }

        $this->eagerLoad = array_unique(array_merge($this->eagerLoad, $relations));
        return $this;
    }

    /**
     * 执行预加载
     */
    protected function eagerLoadRelations(array $models): array
    {
        if (!empty($this->withCount)) {
            $models = $this->eagerLoadRelationCounts($models);
        }

        foreach ($this->hasConstraints as $relation => $constraint) {
            $models = $this->applyHasConstraint($models, $relation, $constraint);
        }

        if (empty($this->eagerLoad)) {
            return $models;
        }

        foreach ($this->eagerLoad as $relation) {
            $this->loadRelation($models, $relation);
        }

        return $models;
    }

    /**
     * 预加载关联计数
     */
    protected function eagerLoadRelationCounts(array $models): array
    {
        foreach ($this->withCount as $withCount) {
            $relation = $withCount['relation'];
            $callback = $withCount['callback'];

            foreach ($models as $model) {
                $count = $this->getRelationCount($model, $relation, $callback);
                $countAttr = $this->getRelationCountName($relation);
                $model->$countAttr = $count;
            }
        }

        return $models;
    }

    /**
     * 获取关联计数
     */
    protected function getRelationCount(Model $model, string $relation, ?\Closure $callback = null): int
    {
        $relationMethod = Str::camel($relation);

        if (!method_exists($model, $relationMethod)) {
            return 0;
        }

        $relationInstance = $model->$relationMethod();

        if ($relationInstance instanceof \Kode\Database\Model\Relation\HasMany ||
            $relationInstance instanceof \Kode\Database\Model\Relation\MorphMany) {
            $foreignKey = $relationInstance->getForeignKey();
            if ($foreignKey === null) {
                return 0;
            }

            $query = new \Kode\Database\Query\QueryBuilder(\Kode\Database\Db\Db::getConnection());
            $query->table($relationInstance->getRelated()->getTable())
                  ->where($foreignKey, '=', $model->{$model->getKeyName()});

            if ($callback !== null) {
                $callback($query);
            }

            return $query->count();
        }

        return 0;
    }

    /**
     * 获取关联计数属性名
     */
    protected function getRelationCountName(string $relation): string
    {
        return Str::snake($relation) . '_count';
    }

    /**
     * 应用 Has 约束
     */
    protected function applyHasConstraint(array $models, string $relation, array $constraint): array
    {
        if (empty($models)) {
            return $models;
        }

        $relationMethod = Str::camel($relation);
        $firstModel = reset($models);

        if (!method_exists($firstModel, $relationMethod)) {
            return $models;
        }

        $relationInstance = $firstModel->$relationMethod();

        if (!($relationInstance instanceof \Kode\Database\Model\Relation\HasMany ||
              $relationInstance instanceof \Kode\Database\Model\Relation\MorphMany)) {
            return $models;
        }

        $foreignKey = $relationInstance->getForeignKey();
        if ($foreignKey === null) {
            return $models;
        }

        $localKey = $firstModel->getKeyName();
        $keys = array_filter(array_unique(array_column($models, $localKey)));

        if (empty($keys)) {
            return $models;
        }

        $query = new \Kode\Database\Query\QueryBuilder(\Kode\Database\Db\Db::getConnection());
        $query->table($relationInstance->getRelated()->getTable())
              ->whereIn($foreignKey, $keys);

        if (isset($constraint['callback']) && is_callable($constraint['callback'])) {
            $constraint['callback']($query);
        }

        if (isset($constraint['operator']) && isset($constraint['count'])) {
            $operator = $constraint['operator'];
            $count = $constraint['count'];

            $groupedCounts = [];
            $results = $query->groupBy($foreignKey)->select([$foreignKey, 'COUNT(*) as cnt'])->get();

            foreach ($results as $result) {
                $groupedCounts[$result[$foreignKey]] = (int) $result['cnt'];
            }

            $filteredModels = [];
            foreach ($models as $model) {
                $modelCount = $groupedCounts[$model->$localKey] ?? 0;
                if ($this->compareCount($modelCount, $operator, $count)) {
                    $filteredModels[] = $model;
                }
            }

            return $filteredModels;
        }

        $existingKeys = $query->distinct()->pluck($foreignKey);

        return array_filter($models, function ($model) use ($localKey, $existingKeys) {
            return in_array($model->$localKey, $existingKeys, false);
        });
    }

    /**
     * 比较计数
     */
    protected function compareCount(int $count, string $operator, int $value): bool
    {
        return match ($operator) {
            '>=' => $count >= $value,
            '>' => $count > $value,
            '<=' => $count <= $value,
            '<' => $count < $value,
            '=' => $count === $value,
            '!=' => $count !== $value,
            default => $count >= $value,
        };
    }

    /**
     * 加载单个关联
     */
    protected function loadRelation(array $models, string $relation): void
    {
        $relationMethod = Str::camel($relation);

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
        } elseif ($relationInstance instanceof \Kode\Database\Model\Relation\MorphOne ||
                  $relationInstance instanceof \Kode\Database\Model\Relation\MorphMany) {
            $this->loadMorphRelation($models, $relationInstance, $relation);
        }
    }

    /**
     * 加载 HasOne/HasMany 关联
     */
    protected function loadHasRelation(array $models, \Kode\Database\Model\Relation\HasOne|\Kode\Database\Model\Relation\HasMany $relation, string $name): void
    {
        $related = $relation->getRelated();
        $foreignKey = $relation->getForeignKey();
        $localKey = $relation->getLocalKey();

        if ($foreignKey === null || $localKey === null) {
            return;
        }

        $keys = array_filter(array_unique(array_column($models, $localKey)));

        if (empty($keys)) {
            return;
        }

        $query = new \Kode\Database\Query\QueryBuilder(\Kode\Database\Db\Db::getConnection());
        $query->table($related->getTable())
              ->whereIn($foreignKey, $keys);

        $results = $query->get();
        $grouped = [];

        foreach ($results as $result) {
            $grouped[$result[$foreignKey]][] = $related->newFromBuilder($result);
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
    protected function loadBelongsToRelation(array $models, \Kode\Database\Model\Relation\BelongsTo $relation, string $name): void
    {
        $related = $relation->getRelated();
        $ownerKey = $relation->getOwnerKey();
        $foreignKey = $relation->getForeignKey();

        if ($ownerKey === null || $foreignKey === null) {
            return;
        }

        $keys = array_filter(array_unique(array_column($models, $foreignKey)));

        if (empty($keys)) {
            return;
        }

        $query = new \Kode\Database\Query\QueryBuilder(\Kode\Database\Db\Db::getConnection());
        $query->table($related->getTable())
              ->whereIn($ownerKey, $keys);

        $results = $query->get();
        $indexed = [];

        foreach ($results as $result) {
            $indexed[$result[$ownerKey]] = $related->newFromBuilder($result);
        }

        foreach ($models as $model) {
            $key = $model->{$foreignKey};
            $model->setRelation($name, $indexed[$key] ?? null);
        }
    }

    /**
     * 加载 BelongsToMany 关联
     */
    protected function loadBelongsToManyRelation(array $models, \Kode\Database\Model\Relation\BelongsToMany $relation, string $name): void
    {
        $related = $relation->getRelated();
        $pivotTable = $relation->getTable();
        $foreignPivotKey = $relation->getForeignPivotKey();
        $relatedPivotKey = $relation->getRelatedPivotKey();

        if ($foreignPivotKey === null || $relatedPivotKey === null) {
            return;
        }

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

        $results = \Kode\Database\Db\Db::select($sql, $keys);
        $grouped = [];

        foreach ($results as $result) {
            $pivotKey = $result['pivot_' . $foreignPivotKey] ?? null;
            if ($pivotKey !== null) {
                unset($result['pivot_' . $foreignPivotKey]);
                $grouped[$pivotKey][] = $related->newFromBuilder($result);
            }
        }

        foreach ($models as $model) {
            $key = $model->{$this->getKeyName()};
            $model->setRelation($name, $grouped[$key] ?? []);
        }
    }

    /**
     * 加载多态关联
     */
    protected function loadMorphRelation(array $models, \Kode\Database\Model\Relation\MorphOne|\Kode\Database\Model\Relation\MorphMany $relation, string $name): void
    {
        $related = $relation->getRelated();
        $foreignKey = $relation->getForeignKey();
        $morphType = $relation->getMorphType();
        $localKey = $relation->getLocalKey();
        $morphValue = $relation->getMorphValue();

        if ($foreignKey === null || $localKey === null) {
            return;
        }

        $keys = array_filter(array_unique(array_column($models, $localKey)));

        if (empty($keys)) {
            return;
        }

        $query = new \Kode\Database\Query\QueryBuilder(\Kode\Database\Db\Db::getConnection());
        $query->table($related->getTable())
              ->where($morphType, '=', $morphValue)
              ->whereIn($foreignKey, $keys);

        $results = $query->get();
        $grouped = [];

        foreach ($results as $result) {
            $grouped[$result[$foreignKey]][] = $related->newFromBuilder($result);
        }

        foreach ($models as $model) {
            $key = $model->{$localKey};
            $items = $grouped[$key] ?? [];

            if ($relation instanceof \Kode\Database\Model\Relation\MorphOne) {
                $model->setRelation($name, $items[0] ?? null);
            } else {
                $model->setRelation($name, $items);
            }
        }
    }

    /**
     * 一对一（正向）
     */
    public function hasOne(string $related, ?string $foreignKey = null, ?string $localKey = null): \Kode\Database\Model\Relation\HasOne
    {
        $instance = $this;
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
        $instance = $this;
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
        $instance = $this;
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
        $instance = $this;
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
        $instance = $this;
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
        $instance = $this;
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
            $this,
            $name
        );
    }

    /**
     * 多态多对多（正向）
     */
    public function morphToMany(string $related, string $name, ?string $table = null, ?string $foreignPivotKey = null, ?string $relatedPivotKey = null): \Kode\Database\Model\Relation\MorphToMany
    {
        $instance = $this;
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
