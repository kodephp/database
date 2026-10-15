<?php

declare(strict_types=1);

namespace Kode\Database\Event;

/**
 * 事务提交事件
 *
 * 由 {@see \Kode\Database\Connection\TransactionObservation} 派发，level 为正在关闭的
 * 嵌套层级（与同层级 TransactionBeginEvent 配对）；time 为提交完成时刻。只在提交
 * 成功后派发，提交失败（含断链）不派发。语义详见 {@see TransactionBeginEvent}。
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
