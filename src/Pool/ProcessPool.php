<?php

declare(strict_types=1);

namespace Kode\Database\Pool;

use Kode\Database\Connection\ConnectorInterface;
use Kode\Database\Exception\ConnectionException;

/**
 * 进程池连接管理器
 * 支持多进程环境下的数据库连接管理
 */
class ProcessPool implements PoolInterface
{
    protected ConnectorInterface $connector;
    protected array $config;
    protected array $processConnections = [];
    protected int $maxConnections = 10;
    protected int $minConnections = 2;
    protected int $maxWaitTime = 30;
    protected bool $initialized = false;
    protected int $processId;
    /** @var array<int, callable> 等待队列：连接释放时的回调 */
    protected array $waitQueue = [];
    /** @var int 当前使用中的连接数（含从池取出未归还 + 直接创建的） */
    protected int $inUseCount = 0;

    public function __construct(array $config, string $driver = 'default')
    {
        $this->config = $config;
        $this->processId = getmypid();
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
     * 初始化进程连接池
     */
    protected function initialize(): void
    {
        if ($this->initialized) {
            return;
        }

        for ($i = 0; $i < $this->minConnections; $i++) {
            $connection = $this->connector->connect($this->config);
            if ($connection) {
                $this->processConnections[] = $connection;
            }
        }

        $this->initialized = true;
    }

    /**
     * 获取连接（进程安全，支持 max_wait_time 等待）
     */
    public function get(): mixed
    {
        $currentPid = getmypid();

        if ($currentPid !== $this->processId) {
            $this->processId = $currentPid;
            $this->processConnections = [];
            $this->inUseCount = 0;
            $this->waitQueue = [];
            $this->initialize();
        }

        // 有空闲连接，直接取用
        if (!empty($this->processConnections)) {
            $this->inUseCount++;
            return array_pop($this->processConnections);
        }

        // 未达上限，直接创建新连接
        if ($this->inUseCount < $this->maxConnections) {
            $this->inUseCount++;
            return $this->connector->connect($this->config);
        }

        // 达到上限，进入等待队列轮询等待（单调时钟，理由同 ConnectionPool）
        $startTime = hrtime(true);
        while ((hrtime(true) - $startTime) / 1e9 < $this->maxWaitTime) {
            // 检查是否有连接被释放回池
            if (!empty($this->processConnections)) {
                $this->inUseCount++;
                return array_pop($this->processConnections);
            }
            // 短暂休眠避免空转，10ms 轮询一次
            usleep(10000);
        }

        throw ConnectionException::make('ProcessPool', '获取连接超时（已达最大连接数 ' . $this->maxConnections . '，等待 ' . $this->maxWaitTime . 's）');
    }

    /**
     * 归还连接（进程安全）
     */
    public function release(mixed $connection): void
    {
        if ($connection === null) {
            return;
        }

        // 减少使用计数
        if ($this->inUseCount > 0) {
            $this->inUseCount--;
        }

        $currentPid = getmypid();

        if ($currentPid !== $this->processId) {
            $this->processId = $currentPid;
            $this->processConnections = [];
            $this->connector->disconnect($connection);
            return;
        }

        if (!$this->connector->isConnected($connection)) {
            $this->connector->disconnect($connection);
            return;
        }

        if (count($this->processConnections) < $this->maxConnections) {
            $this->processConnections[] = $connection;
        } else {
            $this->connector->disconnect($connection);
        }
    }

    /**
     * 清理过期连接
     */
    public function cleanup(): void
    {
        foreach ($this->processConnections as $key => $connection) {
            if (!$this->connector->isConnected($connection)) {
                $this->connector->disconnect($connection);
                unset($this->processConnections[$key]);
            }
        }

        $this->processConnections = array_values($this->processConnections);
    }

    /**
     * 获取连接统计
     */
    public function getStats(): array
    {
        return [
            'type' => 'process',
            'total' => $this->maxConnections,
            'available' => count($this->processConnections),
            'in_use' => $this->inUseCount,
            'min' => $this->minConnections,
            'max' => $this->maxConnections,
            'process_id' => $this->processId,
        ];
    }

    /**
     * 重置连接池
     */
    public function reset(): void
    {
        $this->close();
        $this->initialized = false;
        $this->processConnections = [];
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
        foreach ($this->processConnections as $connection) {
            $this->connector->disconnect($connection);
        }

        $this->processConnections = [];
    }
}
