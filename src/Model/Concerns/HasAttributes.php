<?php

declare(strict_types=1);

namespace Kode\Database\Model\Concerns;

/**
 * 模型属性 Trait
 * 支持获取器、修改器、类型转换、序列化
 */
trait HasAttributes
{
    /** @var array 类型转换 */
    protected array $casts = [];

    /** @var array 获取器 */
    protected array $getters = [];

    /** @var array 修改器 */
    protected array $setters = [];

    /** @var array 追加字段 */
    protected array $appendFields = [];

    /** @var array 隐藏字段 */
    protected array $hidden = [];

    /** @var array 显示字段 */
    protected array $visible = [];

    /** @var array 原始数据 */
    protected array $original = [];

    /** @var array attributes */
    protected array $attributes = [];

    /** @var array relations */
    protected array $relations = [];

    /** @var array 隐藏的 JSON 属性 */
    protected array $hiddenJson = [];

    /** @var array 追加的 JSON 属性 */
    protected array $appendsJson = [];

    /**
     * 检查是否有获取器
     */
    public function hasGetMutator(string $key): bool
    {
        return isset($this->getters[$key]) || method_exists($this, 'get' . ucfirst($key) . 'Attribute');
    }

    /**
     * 检查是否有修改器
     */
    public function hasSetMutator(string $key): bool
    {
        return isset($this->setters[$key]) || method_exists($this, 'set' . ucfirst($key) . 'Attribute');
    }

    /**
     * 获取属性值
     *
     * @param string $key 键名
     * @return mixed
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
     * 设置属性值
     *
     * @param string $key 键名
     * @param mixed $value 值
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
     * 批量设置属性
     *
     * @param array $attributes 属性数组
     * @return $this
     */
    public function setAttributes(array $attributes): static
    {
        foreach ($attributes as $key => $value) {
            $this->setAttribute($key, $value);
        }
        return $this;
    }

    /**
     * 获取器处理
     *
     * @param string $key 键名
     * @param mixed $value 值
     * @return mixed
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
     * 修改器处理
     *
     * @param string $key 键名
     * @param mixed $value 值
     * @return mixed
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
     * 类型转换
     *
     * @param string $key 键名
     * @param mixed $value 值
     * @return mixed
     */
    protected function castAttribute(string $key, mixed $value): mixed
    {
        $type = $this->casts[$key];

        return match ($type) {
            'int', 'integer' => (int) $value,
            'float', 'double' => (float) $value,
            'string' => (string) $value,
            'bool', 'boolean' => (bool) $value,
            'array' => is_array($value) ? $value : json_decode($value, true),
            'json' => is_array($value) ? json_encode($value) : $value,
            'object' => is_object($value) ? $value : json_decode($value),
            'datetime', 'date' => $value instanceof \DateTimeInterface ? $value : new \DateTime($value),
            'timestamp' => $value instanceof \DateTimeInterface ? $value->getTimestamp() : (int) $value,
            'decimal' => (float) $value,
            'uuid' => (string) $value,
            default => $value,
        };
    }

    /**
     * 动态设置类型转换
     *
     * @param array $casts 转换配置
     * @return $this
     */
    public function cast(array $casts): static
    {
        $this->casts = array_merge($this->casts, $casts);
        return $this;
    }

    /**
     * 追加字段
     *
     * @param array $fields 字段列表
     * @return $this
     */
    public function append(array $fields): static
    {
        $this->appendFields = array_merge($this->appendFields, $fields);
        return $this;
    }

    /**
     * 隐藏字段
     *
     * @param array $fields 字段列表
     * @return $this
     */
    public function makeHidden(array $fields): static
    {
        $this->hidden = array_merge($this->hidden, $fields);
        return $this;
    }

    /**
     * 显示字段
     *
     * @param array $fields 字段列表
     * @return $this
     */
    public function makeVisible(array $fields): static
    {
        $this->visible = array_merge($this->visible, $fields);
        return $this;
    }

    /**
     * 设置隐藏字段
     *
     * @param array $fields 字段列表
     */
    public function setHidden(array $fields): void
    {
        $this->hidden = $fields;
    }

    /**
     * 设置显示字段
     *
     * @param array $fields 字段列表
     */
    public function setVisible(array $fields): void
    {
        $this->visible = $fields;
    }

    /**
     * 转换为数组
     *
     * @return array
     */
    public function toArray(): array
    {
        $attributes = $this->attributes;

        foreach ($this->appendFields as $field) {
            if (!isset($attributes[$field])) {
                $attributes[$field] = $this->getAttribute($field);
            }
        }

        foreach ($this->hidden as $field) {
            unset($attributes[$field]);
        }

        if (!empty($this->visible)) {
            $attributes = array_intersect_key($attributes, array_flip($this->visible));
        }

        foreach ($this->relations as $key => $value) {
            if ($value instanceof \Kode\Database\Model\Model) {
                $attributes[$key] = $value->toArray();
            } elseif (is_array($value)) {
                $attributes[$key] = array_map(function ($item) {
                    return $item instanceof \Kode\Database\Model\Model ? $item->toArray() : $item;
                }, $value);
            } else {
                $attributes[$key] = $value;
            }
        }

        return $attributes;
    }

    /**
     * 转换为 JSON
     *
     * @param int $options JSON 选项
     * @return string
     */
    public function toJson(int $options = JSON_UNESCAPED_UNICODE): string
    {
        return json_encode($this->toArray(), $options);
    }

    /**
     * JSON 序列化
     *
     * @return array
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    /**
     * 获取原始数据
     *
     * @return array
     */
    public function getOriginal(): array
    {
        return $this->original;
    }

    /**
     * 获取原始数据（指定键）
     *
     * @param string $key 键名
     * @param mixed $default 默认值
     * @return mixed
     */
    public function getOriginalValue(string $key, mixed $default = null): mixed
    {
        return $this->original[$key] ?? $default;
    }

    /**
     * 检查是否有修改
     *
     * @param string|null $key 键名
     * @return bool
     */
    public function isDirty(?string $key = null): bool
    {
        if ($key !== null) {
            return isset($this->attributes[$key]) &&
                   (!isset($this->original[$key]) || $this->original[$key] !== $this->attributes[$key]);
        }

        return !empty($this->getDirty());
    }

    /**
     * 检查指定键是否有修改
     *
     * @param string $key 键名
     * @return bool
     */
    public function wasChanged(string $key): bool
    {
        return isset($this->original[$key]) && $this->original[$key] !== $this->attributes[$key];
    }

    /**
     * 同步原始数据
     *
     * @return $this
     */
    public function syncOriginal(): static
    {
        $this->original = $this->attributes;
        return $this;
    }

    /**
     * 同步修改的数据
     *
     * @param string|null $key 键名
     * @return $this
     */
    public function syncChanges(?string $key = null): static
    {
        if ($key !== null) {
            $this->original[$key] = $this->attributes[$key] ?? null;
        } else {
            $this->syncOriginal();
        }
        return $this;
    }

    /**
     * 获取 attributes
     *
     * @return array
     */
    public function getAttributes(): array
    {
        return $this->attributes;
    }

    /**
     * 清空 relations
     *
     * @return $this
     */
    public function clearRelations(): static
    {
        if (isset($this->relations)) {
            $this->relations = [];
        }
        return $this;
    }

    /**
     * debug 输出
     *
     * @return array
     */
    public function debug(): array
    {
        return [
            'attributes' => $this->attributes ?? [],
            'original' => $this->original ?? [],
            'casts' => $this->casts ?? [],
            'hidden' => $this->hidden ?? [],
            'visible' => $this->visible ?? [],
        ];
    }
}
