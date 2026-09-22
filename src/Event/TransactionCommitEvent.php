<?php

declare(strict_types=1);

namespace Kode\Database\Event;

/**
 * 事务提交事件
 *
 * 注意：目前没有包内派发点，见 {@see TransactionBeginEvent} 的说明。
 */
class TransactionCommitEvent
{
    protected int $level;
    protected ?string $connection;
    protected float $time;

    public function __construct(int $level = 0, ?string $connection = null)
    {
        $this->level = $level;
        $this->connection = $connection;
        $this->time = microtime(true);
    }

    public function getLevel(): int
    {
        return $this->level;
    }

    public function getConnection(): ?string
    {
        return $this->connection;
    }

    public function getTime(): float
    {
        return $this->time;
    }
}
