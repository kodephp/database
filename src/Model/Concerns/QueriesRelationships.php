<?php

declare(strict_types=1);

namespace Kode\Database\Model\Concerns;

use Kode\Database\Db\Db;

trait QueriesRelationships
{
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

    public function morphTo(string $name): \Kode\Database\Model\Relation\MorphTo
    {
        return new \Kode\Database\Model\Relation\MorphTo(
            $this->newInstance(),
            $name
        );
    }

    public function morphByOne(string $related, string $name, ?string $table = null, ?string $foreignPivotKey = null, ?string $relatedPivotKey = null): \Kode\Database\Model\Relation\MorphToMany
    {
        return $this->morphToMany($related, $name, $table, $foreignPivotKey, $relatedPivotKey);
    }

    public function morphByMany(string $related, string $name, ?string $table = null, ?string $foreignPivotKey = null, ?string $relatedPivotKey = null): \Kode\Database\Model\Relation\MorphToMany
    {
        return $this->morphToMany($related, $name, $table, $foreignPivotKey, $relatedPivotKey);
    }

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

    protected function getMorphPair(string $name, ?string $type, ?string $id): array
    {
        $type = $type ?? $name . '_type';
        $id = $id ?? $name . '_id';
        return [$type, $id];
    }

    protected function joiningTable(): string
    {
        $segments = [last(explode('\\', static::class)), $this->table];
        sort($segments);
        return strtolower(implode('_', $segments));
    }

    public function getForeignKey(): string
    {
        return snake_case(class_basename(static::class)) . '_id';
    }

    protected function newInstance(): static
    {
        return new static();
    }
}
