<?php

declare(strict_types=1);

namespace Kode\Database\Pool;

use Kode\Database\Connection\ConnectorInterface;

/**
 * 协程池连接管理器
 * 专为 Fiber 协程环境设计，支持协程隔离
 */
class FiberPool implements PoolInterface
{
    protected ConnectorInterface $connector;
    protected array $config;
    protected array $connections = [];
    protected array $fiberConnections = [];
    protected int $maxConnections = 20;
    protected int $minConnections = 5;
    protected int $maxWaitTime = 30;
    protected bool $useSwooleChannel = false;
    protected ?object $swooleChannel = null;

    public function __construct(array $config, string $driver = 'default')
    {
        $this->config = $config;
        $this->maxConnections = $config['pool']['max'] ?? 20;
        $this->minConnections = $config['pool']['min'] ?? 5;
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
     * 初始化协程池
     */
    protected function initialize(): void
    {
        if ($this->useSwooleChannel && class_exists('Swoole\\Coroutine\\Channel')) {
            $this->swooleChannel = new \Swoole\Coroutine\Channel($this->maxConnections);

            for ($i = 0; $i < $this->minConnections; $i++) {
                $connection = $this->connector->connect($this->config);
                if ($connection) {
                    $this->swooleChannel->push($connection);
                }
            }
        }

        for ($i = 0; $i < $this->minConnections; $i++) {
            $connection = $this->connector->connect($this->config);
            if ($connection) {
                $this->connections[] = $connection;
            }
        }
    }

    /**
     * 获取连接（协程安全）
     */
    public function get(): mixed
    {
        if ($this->useSwooleChannel && $this->swooleChannel !== null) {
            $connection = $this->swooleChannel->pop($this->maxWaitTime);
            if ($connection !== false) {
                return $connection;
            }
            throw new \Kode\Database\Exception\ConnectionException('FiberPool', '获取连接超时');
        }

        if (class_exists('Fiber')) {
            $fiber = $this->getCurrentFiber();
            if ($fiber !== null) {
                $fiberId = $this->getFiberId($fiber);
                if ($fiberId !== null && isset($this->fiberConnections[$fiberId])) {
                    return $this->fiberConnections[$fiberId];
                }
            }
        }

        if (!empty($this->connections)) {
            return array_pop($this->connections);
        }

        if (count($this->fiberConnections) < $this->maxConnections) {
            return $this->connector->connect($this->config);
        }

        throw new \Kode\Database\Exception\ConnectionException('FiberPool', '连接池已满');
    }

    /**
     * 归还连接（协程安全）
     */
    public function release(mixed $connection): void
    {
        if ($connection === null) {
            return;
        }

        if ($this->useSwooleChannel && $this->swooleChannel !== null) {
            if ($this->connector->isConnected($connection)) {
                $this->swooleChannel->push($connection);
            }
            return;
        }

        if (!$this->connector->isConnected($connection)) {
            $this->connector->disconnect($connection);
            return;
        }

        if (class_exists('Fiber')) {
            $fiber = $this->getCurrentFiber();
            if ($fiber !== null) {
                $fiberId = $this->getFiberId($fiber);
                if ($fiberId !== null) {
                    if (isset($this->fiberConnections[$fiberId])) {
                        $oldConnection = $this->fiberConnections[$fiberId];
                        if ($oldConnection !== $connection) {
                            $this->connector->disconnect($oldConnection);
                        }
                    }
                    $this->fiberConnections[$fiberId] = $connection;
                    return;
                }
            }
        }

        if (count($this->connections) < $this->maxConnections) {
            $this->connections[] = $connection;
        } else {
            $this->connector->disconnect($connection);
        }
    }

    /**
     * 获取当前 Fiber
     */
    protected function getCurrentFiber(): ?object
    {
        if (!class_exists('Fiber')) {
            return null;
        }

        try {
            return @\Fiber::getCurrent();
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * 获取 Fiber ID
     */
    protected function getFiberId(object $fiber): ?int
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
     * 清理过期连接
     */
    public function cleanup(): void
    {
        foreach ($this->connections as $key => $connection) {
            if (!$this->connector->isConnected($connection)) {
                $this->connector->disconnect($connection);
                unset($this->connections[$key]);
            }
        }

        $this->connections = array_values($this->connections);

        foreach ($this->fiberConnections as $fiberId => $connection) {
            if (!$this->connector->isConnected($connection)) {
                $this->connector->disconnect($connection);
                unset($this->fiberConnections[$fiberId]);
            }
        }
    }

    /**
     * 获取连接统计
     */
    public function getStats(): array
    {
        return [
            'type' => 'fiber',
            'total' => $this->maxConnections,
            'available' => count($this->connections),
            'fiber_connections' => count($this->fiberConnections),
            'swoole_channel' => $this->useSwooleChannel,
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
        $this->connections = [];
        $this->fiberConnections = [];
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
     * 设置是否使用 Swoole Channel
     */
    public function setUseSwooleChannel(bool $use): void
    {
        $this->useSwooleChannel = $use && class_exists('Swoole\\Coroutine\\Channel');

        if ($this->useSwooleChannel && $this->swooleChannel === null) {
            $this->swooleChannel = new \Swoole\Coroutine\Channel($this->maxConnections);
        }
    }

    /**
     * 获取当前协程的连接
     */
    public function getFiberConnection(): mixed
    {
        if (class_exists('Fiber')) {
            $fiber = $this->getCurrentFiber();
            if ($fiber !== null) {
                $fiberId = $this->getFiberId($fiber);
                if ($fiberId !== null) {
                    return $this->fiberConnections[$fiberId] ?? null;
                }
            }
        }

        return null;
    }

    /**
     * 关闭连接池
     */
    public function close(): void
    {
        foreach ($this->connections as $connection) {
            $this->connector->disconnect($connection);
        }

        foreach ($this->fiberConnections as $connection) {
            $this->connector->disconnect($connection);
        }

        $this->connections = [];
        $this->fiberConnections = [];

        if ($this->swooleChannel !== null) {
            $this->swooleChannel->close();
            $this->swooleChannel = null;
        }
    }
}
