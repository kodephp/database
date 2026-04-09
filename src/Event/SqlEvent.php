<?php

declare(strict_types=1);

namespace Kode\Database\Event;

/**
 * SQL 事件基类
 */
class SqlEvent
{
    protected string $sql = '';
    protected array $bindings = [];
    protected float $time = 0;
    protected ?string $connection = null;

    public function __construct(string $sql, array $bindings = [], ?string $connection = null)
    {
        $this->sql = $sql;
        $this->bindings = $bindings;
        $this->connection = $connection;
        $this->time = microtime(true);
    }

    public function getSql(): string
    {
        return $this->sql;
    }

    public function getBindings(): array
    {
        return $this->bindings;
    }

    public function getConnection(): ?string
    {
        return $this->connection;
    }

    public function getTime(): float
    {
        return $this->time;
    }

    public function getFormattedSql(): string
    {
        if (empty($this->bindings)) {
            return $this->sql;
        }

        $sql = $this->sql;
        foreach ($this->bindings as $binding) {
            $value = is_numeric($binding) ? $binding : "'" . addslashes((string) $binding) . "'";
            $sql = preg_replace('/\?/', $value, $sql, 1);
        }

        return $sql;
    }
}
