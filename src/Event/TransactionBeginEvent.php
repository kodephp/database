<?php

declare(strict_types=1);

namespace Kode\Database\Event;

/**
 * 事务开始事件
 *
 * 由 {@see \Kode\Database\Connection\TransactionObservation} 在执行器事务边界派发：
 * level 为完成后的新嵌套层级（1 为最外层），与同层级的 TransactionCommitEvent /
 * TransactionRollbackEvent 一一配对；连接名与 SqlEvent 同口径。只在底层 BEGIN /
 * SAVEPOINT 成功后派发，无人监听时不构造事件对象。
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
