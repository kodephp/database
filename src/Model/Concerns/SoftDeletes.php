<?php

declare(strict_types=1);

namespace Kode\Database\Model\Concerns;

trait SoftDeletes
{
    protected string $softDeleteField = 'deleted_at';

    protected bool $withTrashed = false;

    public function delete(): bool
    {
        if ($this->exists) {
            if ($this->usesSoftDeletes()) {
                $this->{$this->softDeleteField} = date('Y-m-d H:i:s');
                return $this->save();
            }
            return $this->forceDelete();
        }
        return false;
    }

    public function forceDelete(): bool
    {
        if (!$this->exists) {
            return false;
        }

        $sql = sprintf(
            'DELETE FROM %s WHERE %s = ?',
            $this->table,
            $this->primaryKey
        );

        $affected = \Kode\Database\Db\Db::delete($sql, [$this->getKey()]);

        if ($affected > 0) {
            $this->exists = false;
        }

        return $affected > 0;
    }

    public function restore(): bool
    {
        if (!$this->exists || !$this->usesSoftDeletes()) {
            return false;
        }

        $this->{$this->softDeleteField} = null;
        return $this->save();
    }

    public function withTrashed(): static
    {
        $clone = clone $this;
        $clone->withTrashed = true;
        return $clone;
    }

    public function onlyTrashed(): static
    {
        $clone = clone $this;
        $clone->withTrashed = false;
        return $clone;
    }

    public function usesSoftDeletes(): bool
    {
        return !empty($this->softDeleteField);
    }

    public function getSoftDeleteField(): string
    {
        return $this->softDeleteField ?? 'deleted_at';
    }

    public function setSoftDeleteField(string $field): static
    {
        $this->softDeleteField = $field;
        return $this;
    }

    protected function applySoftDeletesCondition(\Kode\Database\Query\QueryBuilder $query): void
    {
        if (!$this->withTrashed && $this->usesSoftDeletes()) {
            $query->whereNull($this->softDeleteField);
        }
    }
}
