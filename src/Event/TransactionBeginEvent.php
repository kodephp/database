<?php

declare(strict_types=1);

namespace Kode\Database\Event;

/**
 * 事务开始事件
 *
 * 注意：目前包内没有任何派发点（事务由各执行器/ORM 自己管理，嵌套层级口径还没统一），
 * 注册监听不会收到回调。需要事务观测请先用已接线的 SqlEvent，或见 README「事件监听」。
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
