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
    protected \Swoole\Coroutine\Channel $channel;

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
            $this->channel = new \Swoole\Coroutine\Channel($this->maxConnections);
        }

        // 创建最小连接数
        for ($i = 0; $i < $this->minConnections; $i++) {
            $connection = $this->connector->connect($this->config);
            if ($connection) {
                $this->connections[] = $connection;
                if ($this->channel) {
                    $this->channel->push($connection);
                }
            }
        }

        $this->initialized = true;
    }

    /**
     * 获取连接（协程安全）
     */
    public function get(): mixed
    {
        // Swoole 协程环境
        if ($this->channel && class_exists(\Swoole\Coroutine\Channel::class)) {
            $connection = $this->channel->pop($this->maxWaitTime);
            if ($connection !== false) {
                return $connection;
            }
            throw ConnectionException::timeout('ConnectionPool');
        }

        // Fiber 协程环境
        if (class_exists(\Fiber::class)) {
            $fiberId = \Fiber::getCurrent()->getId();
            if (isset($this->connections[$fiberId])) {
                return $this->connections[$fiberId];
            }
        }

        // 普通环境
        if (!empty($this->connections)) {
            $connection = array_pop($this->connections);
            $this->inUseConnections[spl_object_hash($connection)] = time();
            return $connection;
        }

        // 创建新连接
        if (count($this->inUseConnections) < $this->maxConnections) {
            $connection = $this->connector->connect($this->config);
            $this->inUseConnections[spl_object_hash($connection)] = time();
            return $connection;
        }

        throw ConnectionException::make('ConnectionPool', '连接池已满');
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

        // Fiber 协程环境
        if (class_exists(\Fiber::class)) {
            $fiberId = \Fiber::getCurrent()->getId();
            $this->connections[$fiberId] = $connection;
            unset($this->inUseConnections[$connectionId]);
            return;
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
        $maxIdleTime = $this->config['pool']['max_idle_time'] ?? 3600;

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
