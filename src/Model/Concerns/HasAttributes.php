<?php

declare(strict_types=1);

namespace Kode\Database\Model\Concerns;

trait HasAttributes
{
    protected array $casts = [];

    protected array $getters = [];

    protected array $setters = [];

    protected array $appendFields = [];

    public function hasGetMutator(string $key): bool
    {
        return isset($this->getters[$key]) || method_exists($this, 'get' . ucfirst($key) . 'Attribute');
    }

    public function hasSetMutator(string $key): bool
    {
        return isset($this->setters[$key]) || method_exists($this, 'set' . ucfirst($key) . 'Attribute');
    }

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

    public function setAttribute(string $key, mixed $value): void
    {
        if ($this->hasSetMutator($key)) {
            $this->attributes[$key] = $this->mutateSet($key, $value);
            return;
        }

        $this->attributes[$key] = $value;
    }

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
            default => $value,
        };
    }

    public function cast(array $casts): static
    {
        $this->casts = array_merge($this->casts, $casts);
        return $this;
    }

    public function append(array $fields): static
    {
        $this->appendFields = array_merge($this->appendFields, $fields);
        return $this;
    }

    public function toArray(): array
    {
        $attributes = $this->attributes;

        foreach ($this->appendFields as $field) {
            $attributes[$field] = $this->getAttribute($field);
        }

        return $attributes;
    }
}
