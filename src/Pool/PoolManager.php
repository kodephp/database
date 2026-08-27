<?php

declare(strict_types=1);

namespace Kode\Database\Pool;

use Kode\Database\Connection\ConnectorInterface;
use Kode\Database\Exception\ConnectionException;
use Kode\Database\Pool\ScopedConnection;

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
    protected static array $poolTypes = [];

    /**
     * 初始化连接池
     *
     * @param array $config 数据库配置
     * @param string $driver 驱动名称
     * @param string $poolType 池类型：connection|process|parallel|fiber|single
     */
    public static function init(array $config, string $driver = 'default', string $poolType = 'connection'): void
    {
        self::$driver = $driver;

        // 单连接退化池不受协程运行时影响，无需 Swoole Channel
        if ($poolType === 'single') {
            self::$poolType = $poolType;
            self::$poolTypes[$driver] = $poolType;
            self::$pools[$driver] = new SingleConnectionPool($config, $driver);
            return;
        }

        // 若配置未启用池化（pool 为空 / false / enabled=false），自动退化为单连接池
        if (!self::isPoolEnabled($config) && $poolType === 'connection') {
            $explicitType = $config['pool']['type'] ?? null;
            if ($explicitType === 'single' || !self::isPoolEnabled($config)) {
                self::$poolType = 'single';
                self::$poolTypes[$driver] = 'single';
                self::$pools[$driver] = new SingleConnectionPool($config, $driver);
                return;
            }
        }

        // 允许通过 pool.type 显式指定池类型
        if (isset($config['pool']['type']) && self::supports((string) $config['pool']['type'])) {
            $poolType = (string) $config['pool']['type'];
            if ($poolType === 'single') {
                self::$poolType = $poolType;
                self::$poolTypes[$driver] = $poolType;
                self::$pools[$driver] = new SingleConnectionPool($config, $driver);
                return;
            }
        }

        // 运行时感知：默认 connection 池底层为 Swoole Coroutine\Channel（协程安全）。
        // 在非协程运行时（多进程同步：webman 非 Swoole 模式、PHP-FPM、CLI 常驻等）
        // 自动降级为「进程安全池」(ProcessPool, per-worker 连接缓存)，避免构造期 Fatal，
        // 并使 kode/database 的连接池在主流多进程运行时可用（消除框架级 workaround）。
        // 若需强制使用某种池型，请显式传入 poolType。
        if ($poolType === 'connection' && !self::isCoroutineRuntime()) {
            $poolType = 'process';
        }

        self::$poolType = $poolType;
        self::$poolTypes[$driver] = $poolType;

        self::$pools[$driver] = match ($poolType) {
            'process' => new ProcessPool($config, $driver),
            'parallel' => new ParallelPool($config, $driver),
            'fiber' => new FiberPool($config, $driver),
            'single' => new SingleConnectionPool($config, $driver),
            default => new ConnectionPool($config, $driver),
        };
    }

    /**
     * 判断配置是否启用连接池
     *
     * 无池时应退化为单连接（SingleConnectionPool），而不是抛出“连接池未初始化”。
     * 判定规则：
     *  - 未设置 pool / pool 为 false/null/0/''/[]  => 未启用
     *  - pool 为 true => 启用（使用默认连接池）
     *  - pool 为数组且 enabled===false => 未启用
     *  - pool 为数组且为空 => 未启用
     *  - 其它数组（包含 max/min 等）=> 启用
     */
    public static function isPoolEnabled(array $config): bool
    {
        if (!array_key_exists('pool', $config)) {
            return false;
        }

        $pool = $config['pool'];

        if ($pool === false || $pool === null || $pool === 0 || $pool === '') {
            return false;
        }

        if ($pool === true) {
            return true;
        }

        if (is_array($pool)) {
            if (array_key_exists('enabled', $pool) && !$pool['enabled']) {
                return false;
            }
            if (empty($pool)) {
                return false;
            }
            return true;
        }

        return (bool) $pool;
    }

    /**
     * 判断当前运行时是否真正处于「协程上下文」（而非仅加载了 Swoole 扩展）
     *
     * connection 池底层依赖 Swoole Coroutine\Channel，仅当「当前确实处于协程上下文」时
     * 才能安全使用。仅判断 class_exists(Swoole\Coroutine\Channel) 会在「带 Swoole 扩展的
     * Native 运行时」（普通 CLI、PHP-FPM、webman 非协程模式等）下误判为协程，导致
     * init() 不降级而直接构造 Swoole Channel 池，随后在非协程环境中 Fatal。
     *
     * 因此改用 \Swoole\Coroutine::getUid() 检测真实协程上下文：
     * 未进入协程时返回 -1，进入协程后返回 >= 0（getCid() 语义相同，作为老版本降级）。
     */
    public static function isCoroutineRuntime(): bool
    {
        if (!class_exists(\Swoole\Coroutine::class)) {
            return false;
        }

        $id = method_exists(\Swoole\Coroutine::class, 'getUid')
            ? \Swoole\Coroutine::getUid()
            : \Swoole\Coroutine::getCid();

        return $id >= 0;
    }

    /**
     * 获取连接池
     *
     * 无池时自动退化为单连接池（SingleConnectionPool），避免调用方因未初始化而异常。
     * 退化所需配置优先从 Db 获取（若已初始化），否则以空配置创建单连接池（连接时再报错）。
     */
    public static function getPool(?string $driver = null): PoolInterface
    {
        $driver = $driver ?? self::$driver;

        if (!isset(self::$pools[$driver])) {
            // 尝试无池退化：若 Db 已持有该连接对应配置且该配置未启用池，则自动创建单连接池兜底
            // 避免对未知连接名误用默认配置退化而掩盖“未初始化”错误
            if (class_exists(\Kode\Database\Db\Db::class)) {
                try {
                    $connections = \Kode\Database\Db\Db::getConnections();
                    $defaultConfig = \Kode\Database\Db\Db::getConfig();
                    $hasExplicit = isset($connections[$driver]);
                    $isDefaultDriver = $driver === ($defaultConfig['driver'] ?? null) || $driver === \Kode\Database\Db\Db::getDefaultConnection();
                    // 仅当该 driver 有显式配置，或是默认连接对应池键时，才允许用对应配置退化
                    if ($hasExplicit || $isDefaultDriver) {
                        $config = \Kode\Database\Db\Db::getConfig($driver);
                        if (!empty($config) && !self::isPoolEnabled($config)) {
                            self::$pools[$driver] = new SingleConnectionPool($config, $driver);
                            self::$poolTypes[$driver] = 'single';
                            return self::$pools[$driver];
                        }
                    }
                } catch (\Throwable) {
                }
            }

            throw ConnectionException::make($driver, "连接池未初始化: {$driver}");
        }

        return self::$pools[$driver];
    }

    /**
     * 是否已初始化指定驱动的池（含单连接池）
     */
    public static function hasPool(?string $driver = null): bool
    {
        $driver = $driver ?? self::$driver;
        return isset(self::$pools[$driver]);
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
     * 在作用域内使用连接，结束自动归还（RAII）
     *
     * 等价于 webman/Hyperf「请求结束自动回收连接」：回调执行完毕（含异常）后
     * 总是把连接归还给对应池，调用方无需手动 release。
     *
     * @param callable(mixed $connection): mixed $callback 接收连接并返回结果
     * @param string|null $driver 驱动名称
     * @return mixed 回调的返回值
     *
     * @example
     * $rows = PoolManager::scoped(fn($conn) => $conn->select('SELECT * FROM users'));
     */
    public static function scoped(callable $callback, ?string $driver = null): mixed
    {
        $connection = self::getConnection($driver);

        try {
            return $callback($connection);
        } finally {
            self::releaseConnection($connection, $driver);
        }
    }

    /**
     * 获取一个作用域连接对象（RAII）
     *
     * 返回 {@see ScopedConnection}，在局部变量离开作用域（或显式 release）时自动归还。
     *
     * @param string|null $driver 驱动名称
     * @return ScopedConnection
     */
    public static function getScopedConnection(?string $driver = null): ScopedConnection
    {
        return new ScopedConnection(self::getConnection($driver), $driver);
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
        if ($driver !== null && isset(self::$poolTypes[$driver])) {
            return self::$poolTypes[$driver];
        }
        return self::$poolType;
    }

    /**
     * 检查是否支持指定池类型
     */
    public static function supports(string $poolType): bool
    {
        return in_array($poolType, ['connection', 'process', 'parallel', 'fiber', 'single'], true);
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
        self::$poolTypes = [];
        self::$contextPools = [];
    }
}
