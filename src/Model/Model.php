<?php

declare(strict_types=1);

namespace Kode\Database\Model;

use Kode\Database\Db\Db;
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
 *
 * @property mixed $id
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

    protected array $attributes = [];
    protected array $original = [];
    protected bool $exists = false;

    public function __construct(array $attributes = [])
    {
        $this->fill($attributes);
    }

    /**
     * 批量赋值
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
        if (\in_array($key, $this->fillable)) {
            return true;
        }

        if (!empty($this->guarded) && \in_array($key, $this->guarded)) {
            return false;
        }

        return empty($this->fillable);
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
        $columns = array_keys($this->attributes);
        $values = array_values($this->attributes);

        $sql = sprintf(
            'INSERT INTO %s (%s) VALUES (%s)',
            $this->table,
            implode(', ', $columns),
            implode(', ', array_fill(0, count($values), '?'))
        );

        $result = Db::insert($sql, $values);

        if ($result && $this->getKey() === null) {
            $lastId = Db::select('SELECT LAST_INSERT_ID() as id');
            $this->attributes[$this->primaryKey] = $lastId[0]['id'] ?? null;
        }

        $this->exists = true;
        $this->syncOriginal();

        return $result;
    }

    /**
     * 执行更新
     */
    protected function performUpdate(): bool
    {
        if (!$this->exists) {
            return false;
        }

        $sets = [];
        $values = [];

        foreach ($this->attributes as $key => $value) {
            if ($key !== $this->primaryKey) {
                $sets[] = "{$key} = ?";
                $values[] = $value;
            }
        }

        if ($this->timestamps) {
            $sets[] = "{$this->updatedAtField} = ?";
            $values[] = date($this->dateFormat);
        }

        $values[] = $this->getKey();

        $sql = sprintf(
            'UPDATE %s SET %s WHERE %s = ?',
            $this->table,
            implode(', ', $sets),
            $this->primaryKey
        );

        $affected = Db::update($sql, $values);

        if ($affected > 0) {
            $this->syncOriginal();
        }

        return $affected > 0;
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

        $sql = sprintf(
            'DELETE FROM %s WHERE %s = ?',
            $this->table,
            $this->primaryKey
        );

        $affected = Db::delete($sql, [$this->getKey()]);

        if ($affected > 0) {
            $this->exists = false;
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
    public static function findOrFail(mixed $id): ?static
    {
        $result = static::find($id);

        if ($result === null) {
            throw new \Kode\Database\Exception\QueryException("模型未找到: {$id}");
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
     * 获取所有记录
     */
    public static function all(): array
    {
        $instance = new static();
        return $instance->newQuery()->get();
    }

    /**
     * 创建模型
     */
    public static function create(array $attributes): static
    {
        $instance = new static();
        $instance->fill($attributes);
        $instance->save();
        return $instance;
    }

    /**
     * 更新或创建
     */
    public static function updateOrCreate(array $attributes, array $values = []): static
    {
        $instance = static::where($attributes)->first();

        if ($instance === null) {
            $instance = new static();
            $instance->fill(array_merge($attributes, $values));
        } else {
            foreach ($values as $key => $value) {
                $instance->setAttribute($key, $value);
            }
        }

        $instance->save();
        return $instance;
    }

    /**
     * 查找或创建
     */
    public static function firstOrCreate(array $attributes, ?array $values = null): static
    {
        $instance = static::where($attributes)->first();

        if ($instance === null) {
            $instance = new static();
            $instance->fill(array_merge($attributes, $values ?? []));
            $instance->save();
        }

        return $instance;
    }

    /**
     * 获取新查询构建器
     */
    public function newQuery(): \Kode\Database\Query\QueryBuilder
    {
        return Db::table($this->table);
    }

    /**
     * 查询构建
     */
    public static function query(): \Kode\Database\Query\QueryBuilder
    {
        $instance = new static();
        return $instance->newQuery();
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
        $instance->syncOriginal();
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

        if ($this->hasGetMutator($key)) {
            return $this->mutateGet($key, $value);
        }

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
        if ($this->hasSetMutator($key)) {
            $this->attributes[$key] = $this->mutateSet($key, $value);
            return;
        }

        $this->attributes[$key] = $value;
    }

    /**
     * 获取获取器
     */
    protected function mutateGet(string $key, mixed $value): mixed
    {
        if (isset($this->getters[$key]) && is_callable($this->getters[$key])) {
            return ($this->getters[$key])($value);
        }

        if (method_exists($this, $method = 'get' . ucfirst($key) . 'Attribute')) {
            return $this->$method($value);
        }

        return $value;
    }

    /**
     * 获取修改器
     */
    protected function mutateSet(string $key, mixed $value): mixed
    {
        if (isset($this->setters[$key]) && is_callable($this->setters[$key])) {
            return ($this->setters[$key])($value);
        }

        if (method_exists($this, $method = 'set' . ucfirst($key) . 'Attribute')) {
            return $this->$method($value);
        }

        return $value;
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
        $attributes = $this->attributes;

        foreach ($this->appendFields as $field) {
            $attributes[$field] = $this->getAttribute($field);
        }

        return $attributes;
    }

    /**
     * 获取表名
     */
    public function getTable(): string
    {
        return $this->table;
    }

    /**
     * 获取主键
     */
    public function getPrimaryKey(): string
    {
        return $this->primaryKey;
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
     * 分页
     */
    public static function paginate(int $page = 1, int $perPage = 15): array
    {
        return static::query()->paginate($page, $perPage);
    }
}
