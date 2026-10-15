<?php

declare(strict_types=1);

namespace Kode\Database\Connection;

use Kode\Database\Event\EventManager;
use Kode\Database\Event\TransactionBeginEvent;
use Kode\Database\Event\TransactionCommitEvent;
use Kode\Database\Event\TransactionRollbackEvent;
use Throwable;

/**
 * 事务观测：Begin / Commit / Rollback 三个事件的派发切面。
 *
 * 与 {@see QueryObservation} 同一立场：接线点选在执行器（{@see ExecutorInterface} 的
 * 全部实现），Db 门面、Db\Connection、Migrator、查询构造器最终都汇到这里；ORM 桥接器
 * 自身的事务入口也走本 trait，换引擎不丢观测。
 *
 * 统一的层级口径（README「事务事件」同源）：
 *  - level 是本执行器自身的嵌套计数，1 为最外层；Begin 派发「完成后」的新层级，
 *    Commit / Rollback 派发「正在关闭」的层级，因此 begin(L) 与 commit(L)/rollback(L) 一一配对；
 *  - 只在底层操作成功后派发（事件记的是成功边界）；未跟踪的调用（level 0）不派发；
 *  - 无人监听时连事件对象都不构造，与 SqlEvent 同为零成本；
 *  - 监听器抛异常只落 error_log，绝不外溢到事务本身。
 *
 * PdoConnection 自己维护 transactionLevel（含 DDL 隐式提交校正），直接调
 * {@see dispatchTransactionBegin()} 等并显式传级；纯委托型的 ORM 桥接器用
 * {@see observeBegin()} 等包装器，由本 trait 维护 {@see $observedTransactionLevel}。
 */
trait TransactionObservation
{
    /**
     * 委托型执行器（ORM 桥接器）自身的嵌套计数。
     *
     * PdoConnection 不使用本计数（它有自己的 transactionLevel，且 DDL 隐式提交时
     * 会把层级归零，两套计数并存只会漂移）。
     */
    protected int $observedTransactionLevel = 0;

    /**
     * 委托型执行器的事务开始包装：委托成功后递增层级并派发 begin。
     *
     * @param callable(): void $begin
     */
    protected function observeBegin(callable $begin): void
    {
        $begin();
        $this->observedTransactionLevel++;
        $this->dispatchTransactionBegin($this->observedTransactionLevel);
    }

    /**
     * 委托型执行器的提交包装：委托成功后按「正在关闭的层级」派发再递减。
     *
     * @param callable(): void $commit
     */
    protected function observeCommit(callable $commit): void
    {
        $commit();
        $level = $this->observedTransactionLevel;
        $this->observedTransactionLevel = max(0, $level - 1);
        $this->dispatchTransactionCommit($level);
    }

    /**
     * 委托型执行器的回滚包装：口径与 {@see observeCommit()} 对称。
     *
     * @param callable(): void $rollback
     */
    protected function observeRollback(callable $rollback): void
    {
        $rollback();
        $level = $this->observedTransactionLevel;
        $this->observedTransactionLevel = max(0, $level - 1);
        $this->dispatchTransactionRollback($level);
    }

    /**
     * 派发事务开始事件（$level 为完成后的新层级，应 >= 1）。
     */
    protected function dispatchTransactionBegin(int $level): void
    {
        $this->fireTransaction(TransactionBeginEvent::class, $level);
    }

    /**
     * 派发事务提交事件（$level 为正在关闭的层级，应 >= 1）。
     */
    protected function dispatchTransactionCommit(int $level): void
    {
        $this->fireTransaction(TransactionCommitEvent::class, $level);
    }

    /**
     * 派发事务回滚事件（$level 为正在关闭的层级，应 >= 1）。
     */
    protected function dispatchTransactionRollback(int $level): void
    {
        $this->fireTransaction(TransactionRollbackEvent::class, $level);
    }

    /**
     * 有监听才构造事件对象并触发；监听器异常只留痕不外溢。
     */
    private function fireTransaction(string $eventClass, int $level): void
    {
        if ($level < 1) {
            return;
        }

        $events = EventManager::getInstance();
        if (!$events->hasListener($eventClass)) {
            return;
        }

        // 连接名与查询观测同口径；独立使用本 trait 时可能没有 connectionName()，兜底 null
        $name = method_exists($this, 'connectionName') ? $this->connectionName() : null;

        try {
            $events->trigger(new $eventClass($level, $name));
        } catch (Throwable $e) {
            error_log(sprintf(
                'kode/database 事务观测失败（不影响事务本身）: %s @ %s:%d | 事件: %s level=%d',
                $e->getMessage(),
                $e->getFile(),
                $e->getLine(),
                $eventClass,
                $level,
            ));
        }
    }
}
