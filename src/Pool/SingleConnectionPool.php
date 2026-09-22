<?php

declare(strict_types=1);

namespace Kode\Database\Pool;

use Kode\Database\Connection\ConnectorInterface;

/**
 * 单连接退化池（无池时兜底）
 *
 * 当配置未启用连接池（pool 为空 / false / disabled）时自动退化：
 *  - 始终复用同一底层连接，避免每次查询新建 PDO / ORM 连接
 *  - 保持事务原子性（同一连接名内 beginTransaction/commit 复用同一 PDO）
 *  - release 为空操作（保留连接而非归还池），close 时统一断开
 *  - isConnected 失效时自动重连
 *
 * 与 Db::$connectionCache 语义一致，但通过 PoolInterface 统一入口，
 * 使 PoolManager::getConnection() 在无池时也不抛出“连接池未初始化”错误，
 * 调用方无感知退化。
 */
class SingleConnectionPool implements PoolInterface
{
    protected ConnectorInterface $connector;
    protected array $config;
    protected mixed $connection = null;

    public function __construct(array $config, string $driver = 'default')
    {
        $this->config = $config;
        $this->connector = $this->createConnector($driver);
    }

    protected function createConnector(string $driver): ConnectorInterface
    {
        $connectorClass = match ($driver) {
            'laravel' => \Kode\Database\Connection\LaravelConnector::class,
            'thinkphp' => \Kode\Database\Connection\ThinkPHPConnector::class,
            'symfony' => \Kode\Database\Connection\SymfonyConnector::class,
            'hyperf' => \Kode\Database\Connection\HyperfConnector::class,
            default => \Kode\Database\Connection\LaravelConnector::class,
        };

        return new $connectorClass();
    }

    /**
     * 获取连接（单例复用）
     *
     * 单连接退化池始终复用同一 Executor 实例，底层 PDO 的惰性连接与断线重试由
     * PdoConnection 自身负责（isConnected 在已建连时会真发一条 SELECT 1 探活，每次 get() 都判一遍
     * 等于凭空多一次 DB 往返；:memory: 等场景重建连接还会把库内容和未提交事务一起丢掉）。
     */
    public function get(): mixed
    {
        if ($this->connection !== null) {
            return $this->connection;
        }

        $this->connection = $this->connector->connect($this->config);

        return $this->connection;
    }

    /**
     * 归还连接（单连接池为空操作，保留复用）
     */
    public function release(mixed $connection): void
    {
        if ($connection === null) {
            return;
        }

        // 首次归还时记录为单例
        if ($this->connection === null) {
            $this->connection = $connection;
            return;
        }

        // 同一实例：保留复用，无需操作
        if ($connection === $this->connection) {
            return;
        }

        // 非同一实例：多余连接直接断开，保留原单例
        try {
            $this->connector->disconnect($connection);
        } catch (\Throwable) {
        }
    }

    public function getConnector(): ConnectorInterface
    {
        return $this->connector;
    }

    public function getConfig(): array
    {
        return $this->config;
    }

    public function getStats(): array
    {
        return [
            'type' => 'single',
            'total' => 1,
            'available' => $this->connection !== null ? 1 : 0,
            'in_use' => 0,
            'min' => 1,
            'max' => 1,
            'single' => true,
        ];
    }

    public function close(): void
    {
        if ($this->connection !== null) {
            try {
                $this->connector->disconnect($this->connection);
            } catch (\Throwable) {
            }
            $this->connection = null;
        }
    }
}
