<?php

declare(strict_types=1);

namespace Kode\Database\Pool;

use Kode\Database\Connection\ConnectorInterface;

/**
 * 并行查询池
 * 支持多连接并行执行查询
 */
class ParallelPool implements PoolInterface
{
    protected ConnectorInterface $connector;
    protected array $config;
    protected array $connections = [];
    protected array $waitingQueries = [];
    protected int $maxConnections = 10;
    protected int $availableConnections = 0;
    protected int $maxWaitTime = 30;

    public function __construct(array $config, string $driver = 'default')
    {
        $this->config = $config;
        $this->maxConnections = $config['pool']['max'] ?? 10;
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
        for ($i = 0; $i < $this->maxConnections; $i++) {
            $connection = $this->connector->connect($this->config);
            if ($connection) {
                $this->connections[$i] = $connection;
                $this->availableConnections++;
            }
        }
    }

    /**
     * 获取连接（并行安全）
     */
    public function get(): mixed
    {
        if ($this->availableConnections > 0) {
            foreach ($this->connections as $key => $connection) {
                if ($this->connector->isConnected($connection)) {
                    $this->availableConnections--;
                    unset($this->connections[$key]);
                    return $connection;
                } else {
                    $this->connector->disconnect($connection);
                    unset($this->connections[$key]);
                }
            }
        }

        $connection = $this->connector->connect($this->config);
        return $connection;
    }

    /**
     * 归还连接（并行安全）
     */
    public function release(mixed $connection): void
    {
        if ($connection === null) {
            return;
        }

        if (!$this->connector->isConnected($connection)) {
            $this->connector->disconnect($connection);
            return;
        }

        if (count($this->connections) < $this->maxConnections) {
            $this->connections[] = $connection;
            $this->availableConnections++;
        } else {
            $this->connector->disconnect($connection);
        }
    }

    /**
     * 执行并行查询
     *
     * @param array $queries 查询数组，每项包含 sql 和 bindings
     * @param callable|null $callback 回调函数
     * @return array 结果数组
     */
    public function parallelExecute(array $queries, ?callable $callback = null): array
    {
        $results = [];
        $processes = [];

        foreach ($queries as $index => $query) {
            $sql = $query['sql'] ?? '';
            $bindings = $query['bindings'] ?? [];

            $connection = $this->get();
            $processes[$index] = [
                'connection' => $connection,
                'sql' => $sql,
                'bindings' => $bindings,
            ];
        }

        foreach ($processes as $index => $process) {
            $connection = $process['connection'];

            try {
                if (is_callable($callback)) {
                    $result = $callback($process['sql'], $process['bindings'], $connection);
                } else {
                    $result = $connection->select($process['sql'], $process['bindings']);
                }
                $results[$index] = ['success' => true, 'data' => $result];
            } catch (\Throwable $e) {
                $results[$index] = ['success' => false, 'error' => $e->getMessage()];
            } finally {
                $this->release($connection);
            }
        }

        return $results;
    }

    /**
     * 批量执行查询（使用并行连接）
     *
     * @param array $tasks 任务数组，每项是 callable
     * @param int $concurrency 最大并发数
     * @return array 结果数组
     */
    public function batchExecute(array $tasks, int $concurrency = 5): array
    {
        $results = [];
        $chunks = array_chunk($tasks, $concurrency);

        foreach ($chunks as $chunkIndex => $chunk) {
            $chunkResults = [];

            foreach ($chunk as $taskIndex => $task) {
                $connection = $this->get();

                try {
                    $result = is_callable($task) ? $task($connection) : $task;
                    $chunkResults[$taskIndex] = ['success' => true, 'data' => $result];
                } catch (\Throwable $e) {
                    $chunkResults[$taskIndex] = ['success' => false, 'error' => $e->getMessage()];
                } finally {
                    $this->release($connection);
                }
            }

            $results = array_merge($results, $chunkResults);
        }

        return $results;
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
                $this->availableConnections--;
            }
        }

        $this->connections = array_values($this->connections);
    }

    /**
     * 获取连接统计
     */
    public function getStats(): array
    {
        return [
            'type' => 'parallel',
            'total' => $this->maxConnections,
            'available' => $this->availableConnections,
            'in_use' => count($this->connections) - $this->availableConnections,
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
        $this->availableConnections = 0;
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
        foreach ($this->connections as $connection) {
            $this->connector->disconnect($connection);
        }

        $this->connections = [];
        $this->availableConnections = 0;
    }
}
