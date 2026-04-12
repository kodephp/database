<?php

declare(strict_types=1);

namespace Kode\Database\Model;

use Kode\Database\Model\Concerns\HasAttributes;
use Kode\Database\Model\Concerns\SoftDeletes;
use Kode\Database\Model\Concerns\Timestamps;
use Kode\Database\Model\Concerns\QueriesRelationships;
use Kode\Database\Model\ModelEvent;
use ArrayAccess;
use JsonSerializable;

/**
 * 模型基类
 * 兼容 Hyperf、ThinkPHP、Laravel ORM 使用方式
 */
abstract class Model implements ArrayAccess, JsonSerializable
{
    use HasAttributes;
    use Timestamps;
    use SoftDeletes;
    use QueriesRelationships;
    use ModelEvent;

    protected string $table = '';
    protected string $primaryKey = 'id';
    protected array $fillable = [];
    protected array $guarded = [];
    protected bool $timestamps = true;
    protected string $dateFormat = 'Y-m-d H:i:s';
    protected bool $exists = false;
    protected array $original = [];

    protected string $connection = 'default';
    protected string $database = '';
    protected int $shardingCount = 1;
    protected string $shardingStrategy = 'hash';
    protected string $shardingKey = '';
    protected array $rangeMap = [];

    public function __construct(array $attributes = [])
    {
        $this->fill($attributes);
    }

    /**
     * 填充数据
     */
    public function fill(array $attributes): static
    {
        foreach ($attributes as $key => $value) {
            if ($this->isFillable($key)) {
                $this->setAttribute($key, $value);
            }
        }
        return $this;
    }

    /**
     * 检查字段是否可填充
     */
    protected function isFillable(string $key): bool
    {
        if (empty($this->fillable) && empty($this->guarded)) {
            return true;
        }

        if (!empty($this->fillable)) {
            return in_array($key, $this->fillable, true);
        }

        return !in_array($key, $this->guarded, true);
    }

    /**
     * 创建模型
     */
    public function save(): bool
    {
        if (!$this->beforeSave()) {
            return false;
        }

        $this->setTimestamps();

        if ($this->exists) {
            $result = $this->performUpdate();
        } else {
            $result = $this->performInsert();
        }

        if ($result) {
            $this->afterSave();
        }

        return $result;
    }

    /**
     * 执行插入
     */
    protected function performInsert(): bool
    {
        $sql = sprintf(
            'INSERT INTO %s (%s) VALUES (%s)',
            $this->table,
            implode(', ', array_keys($this->attributes)),
            implode(', ', array_fill(0, count($this->attributes), '?'))
        );

        $id = Db::insert($sql, array_values($this->attributes));

        if ($id) {
            $this->attributes[$this->primaryKey] = $id;
            $this->exists = true;
        }

        return $id > 0;
    }

    /**
     * 执行更新
     */
    protected function performUpdate(): bool
    {
        if (empty($this->getDirty())) {
            return true;
        }

        $sql = sprintf(
            'UPDATE %s SET %s WHERE %s = ?',
            $this->table,
            implode(', ', array_map(fn($k) => "{$k} = ?", array_keys($this->getDirty()))),
            $this->primaryKey
        );

        $values = array_values($this->getDirty());
        $values[] = $this->getKey();

        $affected = Db::update($sql, $values);

        if ($affected > 0) {
            $this->syncOriginal();
        }

        return $affected > 0;
    }

    /**
     * 获取修改的字段
     */
    public function getDirty(): array
    {
        $dirty = [];
        foreach ($this->attributes as $key => $value) {
            if (!array_key_exists($key, $this->original) || $this->original[$key] !== $value) {
                $dirty[$key] = $value;
            }
        }
        return $dirty;
    }

    /**
     * 删除模型
     */
    public function delete(): bool
    {
        if (!$this->exists) {
            return false;
        }

        if ($this->usesSoftDeletes()) {
            if (!$this->beforeDelete()) {
                return false;
            }
            $this->{$this->softDeleteField} = date($this->dateFormat);
            $result = $this->save();
            if ($result) {
                $this->afterDelete();
            }
            return $result;
        }

        return $this->forceDelete();
    }

    /**
     * 强制删除
     */
    public function forceDelete(): bool
    {
        if (!$this->beforeDelete()) {
            return false;
        }

        if (!$this->beforeForceDelete()) {
            return false;
        }

        $sql = sprintf(
            'DELETE FROM %s WHERE %s = ?',
            $this->table,
            $this->primaryKey
        );

        $affected = Db::delete($sql, [$this->getKey()]);

        if ($affected > 0) {
            $this->exists = false;
            $this->afterForceDelete();
            $this->afterDelete();
        }

        return $affected > 0;
    }

    /**
     * 恢复软删除
     */
    public function restore(): bool
    {
        if (!$this->usesSoftDeletes()) {
            return false;
        }

        if (!$this->beforeRestore()) {
            return false;
        }

        $this->{$this->softDeleteField} = null;
        $result = $this->save();

        if ($result) {
            $this->afterRestore();
        }

        return $result;
    }

    /**
     * 通过主键查找
     */
    public static function find(mixed $id): ?static
    {
        $instance = new static();
        $result = Db::table($instance->table)->find($id);

        if ($result) {
            return $instance->newFromBuilder($result);
        }

        return null;
    }

    /**
     * 查找或失败
     */
    public static function findOrFail(mixed $id): static
    {
        $result = static::find($id);

        if ($result === null) {
            throw \Kode\Database\Exception\ModelNotFoundException::notFound(static::class);
        }

        return $result;
    }

    /**
     * 查找第一个
     */
    public static function first(): ?static
    {
        $instance = new static();
        $result = Db::table($instance->table)->first();

        if ($result) {
            return $instance->newFromBuilder($result);
        }

        return null;
    }

    /**
     * 获取所有
     */
    public static function all(): array
    {
        $instance = new static();
        $results = Db::table($instance->table)->get();

        return array_map(fn($r) => (new static())->newFromBuilder($r), $results);
    }

    /**
     * 创建
     */
    public static function create(array $attributes = []): static
    {
        $instance = new static();
        $instance->fill($attributes);
        $instance->save();
        return $instance;
    }

    /**
     * 更新或创建
     */
    public static function updateOrCreate(array $search, array $values = []): static
    {
        $instance = static::where($search)->first();

        if ($instance) {
            foreach ($values as $key => $value) {
                $instance->$key = $value;
            }
            $instance->save();
        } else {
            $instance = static::create(array_merge($search, $values));
        }

        return $instance;
    }

    /**
     * 查找或创建
     */
    public static function firstOrCreate(array $search, array $values = []): static
    {
        $instance = static::where($search)->first();

        if ($instance) {
            return $instance;
        }

        return static::create(array_merge($search, $values));
    }

    /**
     * 创建查询构建器
     */
    public static function query(): \Kode\Database\Query\QueryBuilder
    {
        $instance = new static();
        return $instance->newQuery();
    }

    /**
     * 创建新的查询构建器
     */
    public function newQuery(): \Kode\Database\Query\QueryBuilder
    {
        $query = new \Kode\Database\Query\QueryBuilder(Db::getConnection());
        return $query->table($this->table)->setPrimaryKey($this->primaryKey);
    }

    /**
     * where 条件
     */
    public static function where(string|array $column, mixed $operator = null, mixed $value = null): \Kode\Database\Query\QueryBuilder
    {
        $instance = new static();
        $query = $instance->newQuery();

        if (is_array($column)) {
            foreach ($column as $key => $value) {
                $query->where($key, '=', $value);
            }
        } else {
            $query->where($column, $operator, $value);
        }

        return $query;
    }

    /**
     * whereIn 条件
     */
    public static function whereIn(string $column, array $values): \Kode\Database\Query\QueryBuilder
    {
        $instance = new static();
        return $instance->newQuery()->whereIn($column, $values);
    }

    /**
     * whereNull 条件
     */
    public static function whereNull(string $column): \Kode\Database\Query\QueryBuilder
    {
        $instance = new static();
        return $instance->newQuery()->whereNull($column);
    }

    /**
     * whereNotNull 条件
     */
    public static function whereNotNull(string $column): \Kode\Database\Query\QueryBuilder
    {
        $instance = new static();
        return $instance->newQuery()->whereNotNull($column);
    }

    /**
     * orderBy 排序
     */
    public static function orderBy(string $column, string $direction = 'ASC'): \Kode\Database\Query\QueryBuilder
    {
        $instance = new static();
        return $instance->newQuery()->orderBy($column, $direction);
    }

    /**
     * 分页
     */
    public static function paginate(
        int $page = 1,
        int $perPage = 15,
        string $orderField = 'id',
        string $orderDirection = 'DESC'
    ): array {
        $instance = new static();
        return \Kode\Database\Db\Db::table($instance->table)
            ->orderBy($orderField, $orderDirection)
            ->paginate($page, $perPage);
    }

    /**
     * 获取主键值
     */
    public function getKey(): mixed
    {
        return $this->attributes[$this->primaryKey] ?? null;
    }

    /**
     * 获取主键名
     */
    public function getKeyName(): string
    {
        return $this->primaryKey;
    }

    /**
     * 创建模型实例
     */
    public function newFromBuilder(array $attributes = []): static
    {
        $instance = new static();
        $instance->setAttributes($attributes, true);
        $instance->exists = true;
        return $instance;
    }

    /**
     * 设置属性
     */
    public function setAttributes(array $attributes, bool $sync = false): void
    {
        foreach ($attributes as $key => $value) {
            $this->setAttribute($key, $value);
        }

        if ($sync) {
            $this->syncOriginal();
        }
    }

    /**
     * 同步原始数据
     */
    public function syncOriginal(): void
    {
        $this->original = $this->attributes;
    }

    /**
     * 获取原始值
     */
    public function getOriginal(string $key, mixed $default = null): mixed
    {
        return $this->original[$key] ?? $default;
    }

    /**
     * 检查是否有修改
     */
    public function isDirty(?string $key = null): bool
    {
        if ($key !== null) {
            return ($this->original[$key] ?? null) !== ($this->attributes[$key] ?? null);
        }

        return $this->original !== $this->attributes;
    }

    /**
     * ArrayAccess 实现
     */
    public function offsetExists(mixed $offset): bool
    {
        return isset($this->attributes[$offset]);
    }

    public function offsetGet(mixed $offset): mixed
    {
        return $this->getAttribute($offset);
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        $this->setAttribute($offset, $value);
    }

    public function offsetUnset(mixed $offset): void
    {
        unset($this->attributes[$offset]);
    }

    /**
     * JsonSerializable 实现
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    /**
     * 转换为数组
     */
    public function toArray(): array
    {
        return $this->attributes;
    }

    /**
     * 获取表名
     */
    public function getTable(): string
    {
        return $this->table;
    }

    /**
     * 检查是否已存在
     */
    public function exists(): bool
    {
        return $this->exists;
    }

    /**
     * 是否使用软删除
     */
    protected function usesSoftDeletes(): bool
    {
        return !empty($this->softDeleteField);
    }

    /**
     * 创建新实例
     */
    public function newInstance(): static
    {
        return new static();
    }

    /**
     * 获取表名（兼容 snake_case）
     */
    public function getForeignKey(): string
    {
        $className = basename(str_replace('\\', '/', static::class));
        return \strtolower(\lcfirst($className)) . '_id';
    }

    /**
     * 聚合函数 - 数量
     */
    public static function count(string $column = '*'): int
    {
        return (int) static::query()->count($column);
    }

    /**
     * 聚合函数 - 求和
     */
    public static function sum(string $column): float|int
    {
        return static::query()->sum($column);
    }

    /**
     * 聚合函数 - 平均值
     */
    public static function avg(string $column): float|int
    {
        return static::query()->avg($column);
    }

    /**
     * 聚合函数 - 最大值
     */
    public static function max(string $column): mixed
    {
        return static::query()->max($column);
    }

    /**
     * 聚合函数 - 最小值
     */
    public static function min(string $column): mixed
    {
        return static::query()->min($column);
    }

    /**
     * 获取属性
     */
    public function getAttribute(string $key): mixed
    {
        if ($key === 'id' && !$this->offsetExists('id')) {
            return null;
        }

        if (!$this->offsetExists($key)) {
            return null;
        }

        $value = $this->attributes[$key];

        if (isset($this->casts[$key])) {
            return $this->castAttribute($key, $value);
        }

        return $value;
    }

    /**
     * 设置属性
     */
    public function setAttribute(string $key, mixed $value): void
    {
        $this->attributes[$key] = $value;
    }

    /**
     * 类型转换
     */
    protected function castAttribute(string $key, mixed $value): mixed
    {
        return match ($this->casts[$key] ?? 'string') {
            'int', 'integer' => (int) $value,
            'float', 'double' => (float) $value,
            'bool', 'boolean' => (bool) $value,
            'array' => is_array($value) ? $value : json_decode($value, true),
            'json' => is_array($value) ? json_encode($value) : $value,
            'datetime' => $value instanceof \DateTime ? $value : new \DateTime($value),
            default => $value,
        };
    }

    /**
     * 获取软删除字段
     */
    public function getSoftDeleteField(): string
    {
        return $this->softDeleteField ?? 'deleted_at';
    }

    /**
     * 包含软删除的查询
     */
    public static function withTrashed(): \Kode\Database\Query\QueryBuilder
    {
        $instance = new static();
        return $instance->newQuery();
    }

    /**
     * 仅软删除的查询
     */
    public static function onlyTrashed(): \Kode\Database\Query\QueryBuilder
    {
        $instance = new static();
        return $instance->newQuery()->whereNotNull($instance->getSoftDeleteField());
    }

    /**
     * 获取分表名
     *
     * @param int|string|null $shardingKey 分片键
     * @return string 实际表名
     */
    public function getShardingTable(int|string $shardingKey = null): string
    {
        if ($shardingKey === null) {
            $shardingKey = $this->shardingKey;
        }

        if (empty($shardingKey) || $this->shardingCount <= 1) {
            return $this->table;
        }

        return \Kode\Database\Db\Db::routeSharding(
            $this->table,
            $shardingKey,
            $this->shardingStrategy,
            $this->shardingCount,
            $this->rangeMap
        );
    }

    /**
     * 指定连接
     *
     * @param string $connection 连接名称
     * @return $this
     */
    public function on(string $connection): static
    {
        $this->connection = $connection;
        return $this;
    }

    /**
     * 指定数据库
     *
     * @param string $database 数据库名
     * @return $this
     */
    public function onDatabase(string $database): static
    {
        $this->database = $database;
        return $this;
    }

    /**
     * 设置分片键
     *
     * @param int|string $key 分片键值
     * @return $this
     */
    public function setShardingKey(int|string $key): static
    {
        $this->shardingKey = (string) $key;
        return $this;
    }

    /**
     * 获取分片键
     */
    public function getShardingKey(): string
    {
        return $this->shardingKey;
    }

    /**
     * 获取分片数量
     */
    public function getShardingCount(): int
    {
        return $this->shardingCount;
    }

    /**
     * 获取分片策略
     */
    public function getShardingStrategy(): string
    {
        return $this->shardingStrategy;
    }

    /**
     * 获取连接名称
     */
    public function getConnectionName(): string
    {
        return $this->connection;
    }

    /**
     * 获取数据库名
     */
    public function getDatabaseName(): string
    {
        return $this->database;
    }

    /**
     * 创建新的查询构建器（支持分表）
     */
    public function newShardingQuery(): \Kode\Database\Query\QueryBuilder
    {
        $actualTable = $this->getShardingTable();

        if (!empty($this->database)) {
            $conn = \Kode\Database\Db\Db::connection($this->connection);
            $conn->useDatabase($this->database);
            $query = new \Kode\Database\Query\QueryBuilder($conn->getConnection());
        } else {
            $query = new \Kode\Database\Query\QueryBuilder(\Kode\Database\Db\Db::getConnection($this->connection));
        }

        return $query->table($actualTable)->setPrimaryKey($this->primaryKey);
    }

    /**
     * 跨所有分片查询
     *
     * @param callable|null $callback 回调函数
     * @return array
     */
    public function crossSharding(?callable $callback = null): array
    {
        $results = [];

        for ($i = 0; $i < $this->shardingCount; $i++) {
            $actualTable = "{$this->table}_{$i}";
            $query = \Kode\Database\Db\Db::table($actualTable);
            $result = $callback ? $callback($query, $i) : $query->get();
            $results[$i] = $result;
        }

        return $results;
    }

    /**
     * 批量跨分片操作
     *
     * @param callable $callback 回调函数，接收 (QueryBuilder $query, int $shardingIndex)
     * @return array 汇总结果
     */
    public static function allShards(callable $callback): array
    {
        $instance = new static();

        if ($instance->shardingCount <= 1) {
            return [$callback(\Kode\Database\Db\Db::table($instance->table), 0)];
        }

        $results = [];
        for ($i = 0; $i < $instance->shardingCount; $i++) {
            $actualTable = "{$instance->table}_{$i}";
            $results[$i] = $callback(\Kode\Database\Db\Db::table($actualTable), $i);
        }

        return $results;
    }

    /**
     * 批量创建
     *
     * @param array $records 记录数组
     * @param int $chunkSize 分块大小
     * @return int 成功创建数量
     */
    public static function insertBatch(array $records, int $chunkSize = 1000): int
    {
        if (empty($records)) {
            return 0;
        }

        $instance = new static();
        $query = \Kode\Database\Db\Db::tableWrite($instance->table);
        return $query->insertChunk($records, $chunkSize);
    }

    /**
     * 批量更新
     *
     * @param array $data 更新数据
     * @param string $field 判断字段
     * @return int 成功更新数量
     */
    public static function updateBatch(array $data, string $field = 'id'): int
    {
        if (empty($data)) {
            return 0;
        }

        $instance = new static();
        $affected = 0;

        foreach ($data as $record) {
            if (!isset($record[$field])) {
                continue;
            }

            $id = $record[$field];
            unset($record[$field]);

            if (!empty($record)) {
                $affected += \Kode\Database\Db\Db::tableWrite($instance->table)
                    ->where($field, '=', $id)
                    ->update($record);
            }
        }

        return $affected;
    }

    /**
     * Upsert - 插入或更新
     *
     * @param array $data 数据
     * @param array $uniqueKeys 唯一键
     * @param array $updateKeys 更新字段
     * @return bool
     */
    public static function upsert(array $data, array $uniqueKeys, array $updateKeys = []): bool
    {
        $instance = new static();
        return \Kode\Database\Db\Db::tableWrite($instance->table)
            ->upsert($data, $uniqueKeys, $updateKeys);
    }

    /**
     * 批量 Upsert
     *
     * @param array $records 记录数组
     * @param array $uniqueKeys 唯一键
     * @param array $updateKeys 更新字段
     * @return int 成功数量
     */
    public static function upsertBatch(array $records, array $uniqueKeys, array $updateKeys = []): int
    {
        if (empty($records)) {
            return 0;
        }

        $instance = new static();
        $query = \Kode\Database\Db\Db::tableWrite($instance->table);
        return $query->upsertAll($records, $uniqueKeys, $updateKeys);
    }

    /**
     * 简单分页 - 只返回数据和总数
     *
     * @param int $page 页码
     * @param int $perPage 每页数量
     * @return array
     */
    public static function simplePaginate(int $page = 1, int $perPage = 15): array
    {
        $instance = new static();
        $query = \Kode\Database\Db\Db::table($instance->table);
        $total = $query->count();
        $offset = ($page - 1) * $perPage;
        $items = $query->offset($offset)->limit($perPage)->get();

        return [
            'total' => $total,
            'per_page' => $perPage,
            'current_page' => $page,
            'last_page' => $total > 0 ? (int) ceil($total / $perPage) : 1,
            'items' => $items,
        ];
    }

    /**
     * Chunk 分块处理
     *
     * @param callable $callback 回调函数
     * @param int $chunkSize 每块数量
     * @param string $orderField 排序字段
     * @return bool
     */
    public static function chunk(callable $callback, int $chunkSize = 1000, string $orderField = 'id'): bool
    {
        $instance = new static();
        return \Kode\Database\Db\Db::table($instance->table)
            ->orderBy($orderField)
            ->chunk($callback, $chunkSize);
    }

    /**
     * 游标遍历
     *
     * @param callable $callback 回调函数
     * @param int $chunkSize 每块数量
     * @return bool
     */
    public static function cursor(callable $callback, int $chunkSize = 1000): bool
    {
        $instance = new static();
        return \Kode\Database\Db\Db::table($instance->table)
            ->orderBy($instance->primaryKey)
            ->cursor($callback, $chunkSize);
    }

    /**
     * 查找多个
     *
     * @param array $ids ID数组
     * @return array
     */
    public static function findMany(array $ids): array
    {
        if (empty($ids)) {
            return [];
        }

        $instance = new static();
        return \Kode\Database\Db\Db::table($instance->table)
            ->whereIn($instance->primaryKey, $ids)
            ->get();
    }

    /**
     * 检查记录是否存在
     *
     * @param array $conditions 条件
     * @return bool
     */
    public static function checkExists(array $conditions): bool
    {
        $instance = new static();
        return \Kode\Database\Db\Db::table($instance->table)
            ->where($conditions)
            ->exists();
    }

    /**
     * 获取单条记录的值
     *
     * @param array $conditions 条件
     * @param string $field 字段名
     * @return mixed
     */
    public static function value(array $conditions, string $field)
    {
        $instance = new static();
        return \Kode\Database\Db\Db::table($instance->table)
            ->where($conditions)
            ->value($field);
    }

    /**
     * 获取单列值列表
     *
     * @param string $field 字段名
     * @param array|null $conditions 条件
     * @return array
     */
    public static function pluck(string $field, ?array $conditions = null): array
    {
        $instance = new static();
        $query = \Kode\Database\Db\Db::table($instance->table);

        if ($conditions !== null) {
            $query->where($conditions);
        }

        return $query->pluck($field);
    }

    /**
     * 聚合查询
     *
     * @param array $aggregates 聚合配置 ['count' => '*', 'sum' => 'balance']
     * @param array $conditions 条件
     * @return array
     */
    public static function aggregates(array $aggregates, array $conditions = []): array
    {
        $instance = new static();
        $query = \Kode\Database\Db\Db::table($instance->table);

        if (!empty($conditions)) {
            $query->where($conditions);
        }

        return $query->aggregates($aggregates);
    }

    /**
     * 删除多个
     *
     * @param array $ids ID数组
     * @param bool $force 是否强制删除
     * @return int 删除数量
     */
    public static function destroy(array $ids, bool $force = false): int
    {
        if (empty($ids)) {
            return 0;
        }

        $instance = new static();

        if ($force || !$instance->usesSoftDeletes()) {
            return \Kode\Database\Db\Db::tableWrite($instance->table)
                ->whereIn($instance->primaryKey, $ids)
                ->delete();
        }

        return \Kode\Database\Db\Db::tableWrite($instance->table)
            ->whereIn($instance->primaryKey, $ids)
            ->update([$instance->getSoftDeleteField() => date($instance->dateFormat)]);
    }

    /**
     * 逻辑删除多个
     *
     * @param array $ids ID数组
     * @return int 删除数量
     */
    public static function deleteBatch(array $ids): int
    {
        return static::destroy($ids, false);
    }

    /**
     * 强制删除多个
     *
     * @param array $ids ID数组
     * @return int 删除数量
     */
    public static function forceDeleteBatch(array $ids): int
    {
        return static::destroy($ids, true);
    }

    /**
     * 获取模型元信息
     *
     * @return array
     */
    public static function getInfo(): array
    {
        $instance = new static();
        return [
            'table' => $instance->table,
            'primaryKey' => $instance->primaryKey,
            'connection' => $instance->connection,
            'database' => $instance->database,
            'fillable' => $instance->fillable,
            'guarded' => $instance->guarded,
            'casts' => $instance->casts,
            'timestamps' => $instance->timestamps,
            'softDeletes' => $instance->usesSoftDeletes(),
        ];
    }

    /**
     * 获取表名
     *
     * @return string
     */
    public static function getTableName(): string
    {
        $instance = new static();
        return $instance->table;
    }

    /**
     * 获取主键名
     *
     * @return string
     */
    public static function getPrimaryKeyName(): string
    {
        $instance = new static();
        return $instance->primaryKey;
    }

    /**
     * 检查是否使用软删除
     *
     * @return bool
     */
    public static function hasSoftDeletes(): bool
    {
        $instance = new static();
        return $instance->usesSoftDeletes();
    }

    /**
     * 获取 fillable 字段
     *
     * @return array
     */
    public static function getFillableFields(): array
    {
        $instance = new static();
        return $instance->fillable;
    }

    /**
     * 检查字段是否可以批量赋值
     *
     * @param string $key 字段名
     * @return bool
     */
    public static function checkFillable(string $key): bool
    {
        if (empty(static::getFillableFields())) {
            return !in_array($key, static::getGuardedFields());
        }
        return in_array($key, static::getFillableFields());
    }

    /**
     * 获取 guarded 字段
     *
     * @return array
     */
    public static function getGuardedFields(): array
    {
        $instance = new static();
        return $instance->guarded;
    }

    /**
     * 获取当前表记录数（带条件）
     *
     * @param array $conditions 条件
     * @return int
     */
    public static function countWhere(array $conditions): int
    {
        $instance = new static();
        return \Kode\Database\Db\Db::table($instance->table)->where($conditions)->count();
    }

    /**
     * 执行原生 SQL
     *
     * @param string $sql SQL 语句
     * @param array $bindings 参数
     * @return array
     */
    public static function raw(string $sql, array $bindings = []): array
    {
        return \Kode\Database\Db\Db::select($sql, $bindings);
    }

    /**
     * 获取最后插入 ID
     *
     * @return int|string
     */
    public static function getLastInsertId(): int|string
    {
        $result = \Kode\Database\Db\Db::select('SELECT LAST_INSERT_ID() as id');
        return $result[0]['id'] ?? 0;
    }

    /**
     * 统计各状态数量
     *
     * @param string $field 字段名
     * @return array
     */
    public static function groupByStatus(string $field = 'status'): array
    {
        $instance = new static();
        return \Kode\Database\Db\Db::table($instance->table)
            ->select([$field, 'COUNT(*) as count'])
            ->groupBy($field)
            ->get();
    }

    /**
     * 获取单条记录
     *
     * @param array $conditions 条件
     * @return static|null
     */
    public static function one(array $conditions): ?static
    {
        return static::where($conditions)->first();
    }

    /**
     * 获取单个字段值列表
     *
     * @param string $field 字段名
     * @param array|null $conditions 条件
     * @return array
     */
    public static function values(string $field, ?array $conditions = null): array
    {
        $query = static::where($conditions ?? []);
        return $query->pluck($field);
    }

    /**
     * 分页查询（静态方法）
     *
     * @param int $page 页码
     * @param int $perPage 每页数量
     * @param array $conditions 条件
     * @param string $orderField 排序字段
     * @param string $orderDirection 排序方向
     * @return array
     */
    public static function page(int $page = 1, int $perPage = 15, array $conditions = [], string $orderField = 'id', string $orderDirection = 'DESC'): array
    {
        $query = static::where($conditions);
        return $query->orderBy($orderField, $orderDirection)->paginate($page, $perPage);
    }

    /**
     * 简化分页（静态方法）
     *
     * @param int $page 页码
     * @param int $perPage 每页数量
     * @param array $conditions 条件
     * @return array
     */
    public static function simplePage(int $page = 1, int $perPage = 15, array $conditions = []): array
    {
        $query = static::where($conditions);
        return $query->simplePaginate($page, $perPage);
    }

    /**
     * 批量创建（静态方法）
     *
     * @param array $records 记录数组
     * @param int $chunkSize 分块大小
     * @return int 成功数量
     */
    public static function createBatch(array $records, int $chunkSize = 1000): int
    {
        return static::insertBatch($records, $chunkSize);
    }

    /**
     * 查找或创建（静态方法）
     *
     * @param array $attributes 创建条件
     * @param array $values 创建数据
     * @return static
     */
    public static function findOrCreate(array $attributes, array $values = []): static
    {
        $instance = static::where($attributes)->first();

        if ($instance) {
            return $instance;
        }

        return static::create(array_merge($attributes, $values));
    }

    /**
     * 批量查找（基于指定字段）
     *
     * @param array $values 值数组
     * @param string $field 字段名
     * @return array
     */
    public static function findBy(array $values, string $field = 'id'): array
    {
        if (empty($values)) {
            return [];
        }

        $instance = new static();
        return \Kode\Database\Db\Db::table($instance->table)
            ->whereIn($field, $values)
            ->get();
    }

    /**
     * 条件计数
     *
     * @param array $conditions 条件
     * @return int
     */
    public static function countBy(array $conditions): int
    {
        return static::where($conditions)->count();
    }

    /**
     * 判断是否存在（静态方法）
     *
     * @param array $conditions 条件
     * @return bool
     */
    public static function has(array $conditions): bool
    {
        return static::checkExists($conditions);
    }

    /**
     * 获取模型类名
     *
     * @return string
     */
    public static function getClassName(): string
    {
        return static::class;
    }

    /**
     * 获取表前缀
     *
     * @return string
     */
    public static function getTablePrefix(): string
    {
        $instance = new static();
        if (preg_match('/^(\w+)_/', $instance->table, $matches)) {
            return $matches[1];
        }
        return '';
    }

    /**
     * 获取模型简短类名
     *
     * @return string
     */
    public static function getShortClassName(): string
    {
        $class = static::class;
        return substr($class, strrpos($class, '\\') + 1);
    }

    /**
     * 获取表名（不含前缀）
     *
     * @return string
     */
    public static function getTableNameWithoutPrefix(): string
    {
        $table = static::getTableName();
        $prefix = static::getTablePrefix();
        if ($prefix) {
            return substr($table, strlen($prefix) + 1);
        }
        return $table;
    }

    /**
     * 获取 SQL 日志
     *
     * @return array
     */
    public static function getSqlLog(): array
    {
        return \Kode\Database\Db\Db::getQueryLog();
    }

    /**
     * 清除 SQL 日志
     */
    public static function clearSqlLog(): void
    {
        \Kode\Database\Db\Db::clearQueryLog();
    }

    /**
     * 启用 SQL 日志
     */
    public static function enableSqlLog(): void
    {
        \Kode\Database\Db\Db::enableQueryLog(true);
    }

    /**
     * 禁用 SQL 日志
     */
    public static function disableSqlLog(): void
    {
        \Kode\Database\Db\Db::enableQueryLog(false);
    }

    /**
     * 获取最后执行的 SQL
     *
     * @return string
     */
    public static function getLastSql(): string
    {
        return \Kode\Database\Db\Db::getLastSql();
    }

    /**
     * 检查是否已存在（静态方法）
     *
     * @param mixed $id 主键值
     * @return bool
     */
    public static function existsById(mixed $id): bool
    {
        return static::find($id) !== null;
    }

    /**
     * 批量检查是否存在
     *
     * @param array $ids 主键值数组
     * @return array 存在的 ID 数组
     */
    public static function existsByIds(array $ids): array
    {
        if (empty($ids)) {
            return [];
        }

        $results = static::whereIn('id', $ids)->get();
        return array_column($results, 'id');
    }

    /**
     * 获取最后一条记录
     *
     * @param string $orderField 排序字段
     * @return static|null
     */
    public static function last(string $orderField = 'id'): ?static
    {
        $instance = new static();
        $result = Db::table($instance->table)
            ->orderBy($orderField, 'desc')
            ->first();

        if ($result) {
            return $instance->newFromBuilder($result);
        }

        return null;
    }

    /**
     * 获取第 N 条记录
     *
     * @param int $offset 偏移量
     * @return static|null
     */
    public static function nth(int $offset): ?static
    {
        $instance = new static();
        $result = Db::table($instance->table)
            ->offset($offset)
            ->limit(1)
            ->first();

        if ($result) {
            return $instance->newFromBuilder($result);
        }

        return null;
    }
}
