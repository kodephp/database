<?php

declare(strict_types=1);

namespace Kode\Database\Pool;

use Kode\Database\Connection\ConnectorInterface;
use Kode\Database\Exception\ConnectionException;

/**
 * 连接池管理器
 * 支持协程/进程上下文隔离
 */
class PoolManager
{
    protected static array $pools = [];
    protected static array $contextPools = [];
    protected static ?string $driver = 'default';
    protected static string $poolType = 'connection';

    /**
     * 初始化连接池
     *
     * @param array $config 数据库配置
     * @param string $driver 驱动名称
     * @param string $poolType 池类型：connection|process|parallel|fiber
     */
    public static function init(array $config, string $driver = 'default', string $poolType = 'connection'): void
    {
        self::$driver = $driver;
        self::$poolType = $poolType;

        self::$pools[$driver] = match ($poolType) {
            'process' => new ProcessPool($config, $driver),
            'parallel' => new ParallelPool($config, $driver),
            'fiber' => new FiberPool($config, $driver),
            default => new ConnectionPool($config, $driver),
        };
    }

    /**
     * 获取连接池
     */
    public static function getPool(?string $driver = null): PoolInterface
    {
        $driver = $driver ?? self::$driver;

        if (!isset(self::$pools[$driver])) {
            throw ConnectionException::make($driver, "连接池未初始化: {$driver}");
        }

        return self::$pools[$driver];
    }

    /**
     * 获取连接（协程安全）
     */
    public static function getConnection(?string $driver = null): mixed
    {
        $driver = $driver ?? self::$driver;
        $pool = self::getPool($driver);

        if (class_exists('Fiber')) {
            $fiber = @\Fiber::getCurrent();
            if ($fiber !== null) {
                try {
                    $fiberId = $fiber->getId();
                    $key = "fiber_{$fiberId}";

                    if (!isset(self::$contextPools[$key])) {
                        self::$contextPools[$key] = $pool->get();
                    }

                    return self::$contextPools[$key];
                } catch (\Throwable) {
                }
            }
        }

        return $pool->get();
    }

    /**
     * 归还连接（协程安全）
     */
    public static function releaseConnection(mixed $connection, ?string $driver = null): void
    {
        $driver = $driver ?? self::$driver;
        $pool = self::getPool($driver);

        if (class_exists('Fiber')) {
            $fiber = @\Fiber::getCurrent();
            if ($fiber !== null) {
                try {
                    $fiberId = $fiber->getId();
                    $key = "fiber_{$fiberId}";
                    unset(self::$contextPools[$key]);
                } catch (\Throwable) {
                }
            }
        }

        $pool->release($connection);
    }

    /**
     * 执行并行查询
     *
     * @param array $queries 查询数组
     * @param callable|null $callback 回调函数
     * @param string|null $driver 驱动名称
     * @return array
     */
    public static function parallelExecute(array $queries, ?callable $callback = null, ?string $driver = null): array
    {
        $driver = $driver ?? self::$driver;
        $pool = self::getPool($driver);

        if ($pool instanceof ParallelPool) {
            return $pool->parallelExecute($queries, $callback);
        }

        $results = [];
        foreach ($queries as $index => $query) {
            $sql = $query['sql'] ?? '';
            $bindings = $query['bindings'] ?? [];

            try {
                if (is_callable($callback)) {
                    $results[$index] = ['success' => true, 'data' => $callback($sql, $bindings)];
                } else {
                    $connection = $pool->get();
                    $result = $connection->select($sql, $bindings);
                    $pool->release($connection);
                    $results[$index] = ['success' => true, 'data' => $result];
                }
            } catch (\Throwable $e) {
                $results[$index] = ['success' => false, 'error' => $e->getMessage()];
            }
        }

        return $results;
    }

    /**
     * 批量执行任务
     *
     * @param array $tasks 任务数组
     * @param int $concurrency 并发数
     * @param string|null $driver 驱动名称
     * @return array
     */
    public static function batchExecute(array $tasks, int $concurrency = 5, ?string $driver = null): array
    {
        $driver = $driver ?? self::$driver;
        $pool = self::getPool($driver);

        if ($pool instanceof ParallelPool) {
            return $pool->batchExecute($tasks, $concurrency);
        }

        $results = [];
        $chunks = array_chunk($tasks, $concurrency);

        foreach ($chunks as $chunk) {
            foreach ($chunk as $task) {
                try {
                    $result = is_callable($task) ? $task() : $task;
                    $results[] = ['success' => true, 'data' => $result];
                } catch (\Throwable $e) {
                    $results[] = ['success' => false, 'error' => $e->getMessage()];
                }
            }
        }

        return $results;
    }

    /**
     * 获取连接统计
     */
    public static function getStats(?string $driver = null): array
    {
        $driver = $driver ?? self::$driver;
        $pool = self::getPool($driver);

        return $pool->getStats();
    }

    /**
     * 获取当前池类型
     */
    public static function getPoolType(?string $driver = null): string
    {
        return self::$poolType;
    }

    /**
     * 检查是否支持指定池类型
     */
    public static function supports(string $poolType): bool
    {
        return in_array($poolType, ['connection', 'process', 'parallel', 'fiber'], true);
    }

    /**
     * 清除所有连接池
     */
    public static function clear(): void
    {
        foreach (self::$pools as $pool) {
            if ($pool instanceof PoolInterface) {
                $pool->close();
            }
        }

        self::$pools = [];
        self::$contextPools = [];
    }
}
