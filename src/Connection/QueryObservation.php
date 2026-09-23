<?php

declare(strict_types=1);

namespace Kode\Database\Connection;

use Kode\Database\Db\Connection as DbConnection;
use Kode\Database\Db\Db;
use Kode\Database\Event\EventManager;
use Kode\Database\Event\SqlEvent;
use Throwable;

/**
 * 查询观测：前/后钩子 + 查询日志 + SqlEvent 派发，统一挂在执行器上。
 *
 * 包内三套观测 API（Db::enableQueryLog / Db\Connection::beforeQuery+afterQuery / Event\*）
 * 历史上谁也没被调用过：日志恒空、钩子恒不触发、事件从不派发。接线点选在执行器，
 * 因为 QueryBuilder 直接持有裸执行器、连接池返回的也是执行器本身，绕过 Db\Connection 门面；
 * 放在门面里会漏掉大半调用路径。
 *
 * 由 {@see PdoConnection} 与各 ORM 桥接器共同使用：装了 Laravel / ThinkPHP / Symfony / Hyperf
 * 之后观测照样生效，不然「换 ORM 就没日志」又是一处文档与行为不符。
 */
trait QueryObservation
{
    /**
     * 本执行器所属的连接名（由 Db::addConnection() 写进配置）。
     */
    public function connectionName(): ?string
    {
        $name = $this->config['connection_name'] ?? null;

        return is_string($name) && $name !== '' ? $name : null;
    }

    /**
     * 是否有人关心查询观测：开了查询日志、挂了查询钩子、或注册了事件监听器。
     *
     * 三条都是内存里的读操作，且必须在「计时之前」判断 —— 没人关心时既不测耗时也不构造事件对象，
     * 默认配置下的开销与未接线前完全一致。
     */
    protected static function watching(): bool
    {
        return Db::isQueryLogEnabled()
            || DbConnection::hasQueryHooks()
            || EventManager::getInstance()->hasAny();
    }

    /**
     * 把一次 SQL 的执行包进观测：算耗时、触发钩子、记日志、派发事件。
     *
     * $execute 收到的是「钩子改写后」的 SQL 与参数，耗时口径则是「调用方实际感受到的时间」
     * （传进来的 callable 应已含断连重试等内部补偿），两边都对得上日志。
     *
     * 前钩子的异常会直接终止查询（它本来就有改写入参/拦下危险 SQL 的用途），
     * 后钩子与监听器只能观测、改不了结果，所以异常一律就地留痕（见 {@see report()}）。
     *
     * @param callable(string, array): mixed $execute
     */
    protected function observe(string $sql, array $bindings, callable $execute): mixed
    {
        if (!static::watching()) {
            return $execute($sql, $bindings);
        }

        $keys = $this->connectionName() === null ? ['*'] : ['*', $this->connectionName()];
        [$sql, $bindings] = DbConnection::fireBeforeQuery($keys, $sql, $bindings, $this);
        // 单调时钟：microtime(true) 跟着系统走时跳（NTP 校时/手动改表会让耗时变负），
        // 且只有微秒粒度 —— 本地毫秒级查询会撞上同一个 tick，耗时记成 0。
        $started = hrtime(true);

        try {
            $result = $execute($sql, $bindings);
        } catch (Throwable $e) {
            $this->report($sql, $bindings, (hrtime(true) - $started) / 1e9, $e, $keys);

            throw $e;
        }

        $this->report($sql, $bindings, (hrtime(true) - $started) / 1e9, $result, $keys);

        return $result;
    }

    /**
     * 把一次执行的结果（或异常）广播给三个观测点。
     *
     * @param array<string> $keys
     */
    private function report(string $sql, array $bindings, float $seconds, mixed $result, array $keys): void
    {
        $name = $this->connectionName();

        // 三个观测点各自独立成段：一个写坏的 after 钩子不该把日志记录和事件监听器一起带走。
        $this->safely(fn () => Db::logQuery($sql, $bindings, $seconds, $name), $sql);
        $this->safely(fn () => DbConnection::fireAfterQuery($keys, $sql, $bindings, $result, $this, $seconds), $sql);

        $error = $result instanceof Throwable ? $result : null;
        $this->safely(function () use ($sql, $bindings, $name, $seconds, $error): void {
            $events = EventManager::getInstance();
            if ($events->hasListener(SqlEvent::class)) {
                $events->trigger(new SqlEvent($sql, $bindings, $name, $seconds, $error));
            }
        }, $sql);
    }

    /**
     * 执行单个观测步骤，异常只留痕不外溢。
     *
     * 一个日志钩子写坏了就把正常查询打成 500，比丢几条日志严重得多；
     * 但静默吞掉又查不到原因，所以落到 error_log 并带上出处。
     *
     * @param callable(): void $step
     */
    private function safely(callable $step, string $sql): void
    {
        try {
            $step();
        } catch (Throwable $e) {
            error_log(sprintf(
                "kode/database 查询观测失败（不影响查询本身）: %s @ %s:%d | SQL: %s",
                $e->getMessage(),
                $e->getFile(),
                $e->getLine(),
                strlen($sql) > 200 ? substr($sql, 0, 200).'...' : $sql,
            ));
        }
    }
}
