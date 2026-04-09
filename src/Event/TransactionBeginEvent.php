<?php

declare(strict_types=1);

namespace Kode\Database\Event;

/**
 * 事务开始事件
 */
class TransactionBeginEvent
{
    protected int $level;
    protected ?string $connection;

    public function __construct(int $level = 0, ?string $connection = null)
    {
        $this->level = $level;
        $this->connection = $connection;
    }

    public function getLevel(): int
    {
        return $this->level;
    }

    public function getConnection(): ?string
    {
        return $this->connection;
    }
}
