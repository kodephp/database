<?php

declare(strict_types=1);

namespace Kode\Database\Model\Concerns;

trait SoftDeletes
{
    protected string $softDeleteField = 'deleted_at';

    protected bool $withTrashed = false;

    public function delete(): bool
    {
        if ($this->usesSoftDeletes()) {
            $this->{$this->softDeleteField} = date('Y-m-d H:i:s');
            return $this->save();
        }

        return parent::delete();
    }

    public function forceDelete(): bool
    {
        return parent::delete();
    }

    public function restore(): bool
    {
        if ($this->usesSoftDeletes()) {
            $this->{$this->softDeleteField} = null;
            return $this->save();
        }

        return false;
    }

    public function withTrashed(): static
    {
        $this->withTrashed = true;
        return $this;
    }

    public function onlyTrashed(): static
    {
        $this->withTrashed = false;
        return $this;
    }

    protected function usesSoftDeletes(): bool
    {
        return !empty($this->softDeleteField);
    }

    protected function applySoftDeletes(): void
    {
        if (!$this->withTrashed && $this->usesSoftDeletes()) {
            $this->getQuery()->whereNull($this->table . '.' . $this->softDeleteField);
        }
    }
}
