<?php

declare(strict_types=1);

namespace Kode\Database\Pool;

use Kode\Database\Connection\ConnectorInterface;
use Kode\Database\Exception\ConnectionException;

/**
 * 连接池实现
 * 支持多进程、多线程、协程环境下的连接管理
 */
class ConnectionPool implements PoolInterface
{
    protected ConnectorInterface $connector;
    protected array $config;
    protected array $connections = [];
    protected array $inUseConnections = [];
    protected int $maxConnections = 10;
    protected int $minConnections = 2;
    protected int $maxWaitTime = 30; // 最大等待时间（秒）
    protected bool $initialized = false;
    /**
     * Swoole 协程 Channel（仅协程运行时存在）。
     * 非 Swoole 环境为 null，此时退化为「per-worker 连接缓存」（数组管理），不会因访问未初始化属性而 Fatal。
     */
    protected ?\Swoole\Coroutine\Channel $channel = null;
    /** @var int|null Swoole Timer ID，用于定时清理空闲连接 */
    protected ?int $cleanupTimerId = null;

    public function __construct(array $config, string $driver = 'default')
    {
        $this->config = $config;
        $this->maxConnections = $config['pool']['max'] ?? 10;
        $this->minConnections = $config['pool']['min'] ?? 2;
        $this->maxWaitTime = $config['pool']['max_wait_time'] ?? 30;
        $this->connector = $this->createConnector($driver);
        $this->initialize();
    }

    protected function createConnector(string $driver): ConnectorInterface
    {
        $connectorClass = match ($driver) {
            'laravel' => \Kode\Database\Connection\LaravelConnector::class,
            'thinkphp' => \Kode\Database\Connection\ThinkPHPConnector::class,
            default => \Kode\Database\Connection\LaravelConnector::class,
        };

        return new $connectorClass();
    }

    /**
     * 初始化连接池
     */
    protected function initialize(): void
    {
        if ($this->initialized) {
            return;
        }

        // 创建 Swoole Channel 用于协程安全的连接管理
        if (class_exists(\Swoole\Coroutine\Channel::class)) {
            try {
                $this->channel = new \Swoole\Coroutine\Channel($this->maxConnections);
            } catch (\Throwable) {
                $this->channel = null;
            }
        }

        // 创建最小连接数
        for ($i = 0; $i < $this->minConnections; $i++) {
            $connection = $this->connector->connect($this->config);
            if ($connection) {
                $this->connections[] = $connection;
                if ($this->channel) {
                    try {
                        $this->channel->push($connection);
                    } catch (\Throwable) {
                        // 非协程环境下 Channel 不可用，回退到数组管理
                        $this->channel = null;
                    }
                }
            }
        }

        // 启动定时清理空闲连接（仅 Swoole 环境，且在协程上下文中才需要高频清理；Native 下由每次 get/cleanup 触发）
        if (class_exists(\Swoole\Timer::class)) {
            try {
                // 每 60 秒清理一次，max_idle_time 默认 300s (5分钟)
                $this->cleanupTimerId = \Swoole\Timer::tick(60000, [$this, 'cleanup']);
            } catch (\Throwable) {
                $this->cleanupTimerId = null;
            }
        }

        $this->initialized = true;
    }

    /**
     * 获取连接（协程安全）
     */
    public function get(): mixed
    {
        if ($this->channel && class_exists('Swoole\\Coroutine\\Channel')) {
            try {
                $connection = $this->channel->pop($this->maxWaitTime);
                if ($connection !== false) {
                    return $connection;
                }
                throw ConnectionException::timeout('ConnectionPool');
            } catch (\Throwable $e) {
                // 非协程环境下 Channel 不可用，回退到轮询等待逻辑
                if ($e instanceof ConnectionException) {
                    throw $e;
                }
                $this->channel = null;
            }
        }

        $fiber = $this->getCurrentFiber();
        if ($fiber !== null) {
            $fiberId = $this->getFiberId($fiber);
            if ($fiberId !== null && isset($this->connections[$fiberId])) {
                return $this->connections[$fiberId];
            }
        }

        if (!empty($this->connections)) {
            $connection = array_pop($this->connections);
            $this->inUseConnections[spl_object_hash($connection)] = time();
            return $connection;
        }

        if (count($this->inUseConnections) < $this->maxConnections) {
            $connection = $this->connector->connect($this->config);
            $this->inUseConnections[spl_object_hash($connection)] = time();
            return $connection;
        }

        // Native / Fiber 非 Channel 场景：轮询等待直到 max_wait_time，避免突发 c200 快速失败
        // 对应 Swoole 分支的 Channel::pop(maxWaitTime) 语义
        $startTime = microtime(true);
        while (microtime(true) - $startTime < $this->maxWaitTime) {
            if (!empty($this->connections)) {
                $connection = array_pop($this->connections);
                $this->inUseConnections[spl_object_hash($connection)] = time();
                return $connection;
            }
            if (count($this->inUseConnections) < $this->maxConnections) {
                $connection = $this->connector->connect($this->config);
                $this->inUseConnections[spl_object_hash($connection)] = time();
                return $connection;
            }
            usleep(10000); // 10ms 轮询
        }

        throw ConnectionException::make('ConnectionPool', '连接池已满（等待 ' . $this->maxWaitTime . 's 超时，max=' . $this->maxConnections . '）');
    }

    /**
     * 获取当前 Fiber（如果存在）
     */
    private function getCurrentFiber(): ?object
    {
        if (!class_exists('Fiber')) {
            return null;
        }

        try {
            $fiber = @\Fiber::getCurrent();
            return $fiber;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * 获取 Fiber ID
     */
    private function getFiberId(object $fiber): ?int
    {
        if (!method_exists($fiber, 'getId')) {
            return null;
        }

        try {
            return $fiber->getId();
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * 归还连接（协程安全）
     */
    public function release(mixed $connection): void
    {
        if ($connection === null) {
            return;
        }

        $connectionId = spl_object_hash($connection);

        // 检查连接是否有效
        if (!$this->connector->isConnected($connection)) {
            $this->connector->disconnect($connection);
            unset($this->inUseConnections[$connectionId]);
            return;
        }

        // Swoole 协程环境
        if ($this->channel && class_exists(\Swoole\Coroutine\Channel::class)) {
            if (!$this->channel->isFull()) {
                $this->channel->push($connection);
                unset($this->inUseConnections[$connectionId]);
                return;
            }
        }

        // Fiber 协程环境（须先确认确实处于 Fiber 内，否则 getCurrent() 返回 null 会致命）
        if (class_exists(\Fiber::class)) {
            $fiber = $this->getCurrentFiber();
            if ($fiber !== null) {
                $fiberId = $this->getFiberId($fiber);
                if ($fiberId !== null) {
                    $this->connections[$fiberId] = $connection;
                    unset($this->inUseConnections[$connectionId]);
                    return;
                }
            }
        }

        // 普通环境
        if (count($this->connections) < $this->maxConnections) {
            $this->connections[] = $connection;
            unset($this->inUseConnections[$connectionId]);
            return;
        }

        // 连接池已满，关闭连接
        $this->connector->disconnect($connection);
        unset($this->inUseConnections[$connectionId]);
    }

    /**
     * 清理过期连接
     */
    public function cleanup(): void
    {
        $currentTime = time();
        // 默认 300s (5分钟)，可通过配置 pool.max_idle_time 覆盖
        $maxIdleTime = $this->config['pool']['max_idle_time'] ?? 300;

        foreach ($this->connections as $key => $connection) {
            if (!$this->connector->isConnected($connection)) {
                $this->connector->disconnect($connection);
                unset($this->connections[$key]);
            }
        }

        foreach ($this->inUseConnections as $connectionId => $lastUsed) {
            if ($currentTime - $lastUsed > $maxIdleTime) {
                // 清理长时间未使用的连接
                unset($this->inUseConnections[$connectionId]);
            }
        }
    }

    /**
     * 获取连接统计
     */
    public function getStats(): array
    {
        return [
            'total' => $this->maxConnections,
            'available' => count($this->connections) + ($this->channel ? $this->channel->length() : 0),
            'in_use' => count($this->inUseConnections),
            'min' => $this->minConnections,
            'max' => $this->maxConnections,
        ];
    }

    /**
     * 重置连接池
     */
    public function reset(): void
    {
        $this->close();
        $this->initialized = false;
        $this->connections = [];
        $this->inUseConnections = [];
        $this->initialize();
    }

    /**
     * 获取连接器
     */
    public function getConnector(): ConnectorInterface
    {
        return $this->connector;
    }

    /**
     * 获取配置
     */
    public function getConfig(): array
    {
        return $this->config;
    }

    /**
     * 关闭连接池
     */
    public function close(): void
    {
        // 停止定时清理
        if ($this->cleanupTimerId !== null && class_exists(\Swoole\Timer::class)) {
            \Swoole\Timer::clear($this->cleanupTimerId);
            $this->cleanupTimerId = null;
        }

        // 关闭空闲连接
        foreach ($this->connections as $connection) {
            $this->connector->disconnect($connection);
        }

        // 关闭使用中的连接
        foreach ($this->inUseConnections as $connectionId => $lastUsed) {
            // 这里不能强制关闭使用中的连接，应该等待它们归还
        }

        $this->connections = [];
        $this->inUseConnections = [];

        if ($this->channel) {
            $this->channel->close();
        }
    }
}
