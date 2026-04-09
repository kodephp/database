<?php

declare(strict_types=1);

namespace Kode\Database\Schema;

/**
 * 外键定义
 */
class ForeignKey
{
    protected string $column;
    protected ?string $referencedTable = null;
    protected ?string $referencedColumn = null;
    protected ?string $onDelete = null;
    protected ?string $onUpdate = null;

    public function __construct(string $column)
    {
        $this->column = $column;
    }

    public function references(string $column): self
    {
        $this->referencedColumn = $column;
        return $this;
    }

    public function on(string $table): self
    {
        $this->referencedTable = $table;
        return $this;
    }

    public function onDelete(string $action): self
    {
        $this->onDelete = $action;
        return $this;
    }

    public function onUpdate(string $action): self
    {
        $this->onUpdate = $action;
        return $this;
    }

    public function toSql(): string
    {
        $sql = "FOREIGN KEY ({$this->column}) REFERENCES ";
        $sql .= "{$this->referencedTable}({$this->referencedColumn})";

        if ($this->onDelete) {
            $sql .= " ON DELETE {$this->onDelete}";
        }

        if ($this->onUpdate) {
            $sql .= " ON UPDATE {$this->onUpdate}";
        }

        return $sql;
    }
}
