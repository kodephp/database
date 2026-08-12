<?php

declare(strict_types=1);

namespace Kode\Database\Connection\Bridge;

use Doctrine\DBAL\Connection as DbalConnection;
use Doctrine\DBAL\DriverManager;
use Kode\Database\Connection\ExecutorInterface;

/**
 * Symfony 体系桥接器（基于 Doctrine DBAL）
 *
 * 复用项目既有的 Doctrine\DBAL\Connection 实例；若未提供，则按配置自行通过
 * DriverManager 创建一个。从而与 Symfony ORM（Doctrine）生态融合。
 * 当 doctrine/dbal 未安装时，连接器自动回退到内置 PdoConnection。
 */
class SymfonyBridge implements ExecutorInterface
{
    public function __construct(
        protected array $config = [],
        protected ?DbalConnection $connection = null
    ) {
        if ($this->connection === null && class_exists(DriverManager::class)) {
            $this->connection = DriverManager::getConnection($this->toDbalParams());
        }
    }

    /**
     * 将 kode 配置转换为 Doctrine DBAL 连接参数
     */
    protected function toDbalParams(): array
    {
        $driver = strtolower($this->config['driver'] ?? 'mysql');

        $map = [
            'mysql' => 'pdo_mysql',
            'pgsql' => 'pdo_pgsql',
            'postgres' => 'pdo_pgsql',
            'postgresql' => 'pdo_pgsql',
            'sqlite' => 'pdo_sqlite',
            'sqlsrv' => 'pdo_sqlsrv',
            'sqlserver' => 'pdo_sqlsrv',
        ];

        return [
            'driverClass' => null,
            'driver' => $map[$driver] ?? 'pdo_mysql',
            'host' => $this->config['host'] ?? '127.0.0.1',
            'port' => $this->config['port'] ?? ($driver === 'pgsql' ? 5432 : 3306),
            'dbname' => $this->config['database'] ?? '',
            'user' => $this->config['username'] ?? $this->config['user'] ?? null,
            'password' => $this->config['password'] ?? $this->config['pass'] ?? null,
            'charset' => $this->config['charset'] ?? 'utf8mb4',
        ];
    }

    protected function conn(): DbalConnection
    {
        if ($this->connection === null) {
            throw new \Kode\Database\Exception\ConnectionException('Doctrine DBAL 连接未初始化');
        }
        return $this->connection;
    }

    #[\Override]
    public function select(string $sql, array $bindings = []): array
    {
        return $this->conn()->fetchAllAssociative($sql, $bindings);
    }

    #[\Override]
    public function insert(string $sql, array $bindings = []): int|string
    {
        $this->conn()->executeStatement($sql, $bindings);
        return $this->conn()->lastInsertId();
    }

    #[\Override]
    public function update(string $sql, array $bindings = []): int
    {
        return $this->conn()->executeStatement($sql, $bindings);
    }

    #[\Override]
    public function delete(string $sql, array $bindings = []): int
    {
        return $this->conn()->executeStatement($sql, $bindings);
    }

    #[\Override]
    public function statement(string $sql): bool
    {
        $this->conn()->executeStatement($sql);
        return true;
    }

    #[\Override]
    public function beginTransaction(): void
    {
        $this->conn()->beginTransaction();
    }

    #[\Override]
    public function commit(): void
    {
        $this->conn()->commit();
    }

    #[\Override]
    public function rollBack(): void
    {
        $this->conn()->rollBack();
    }

    #[\Override]
    public function isConnected(): bool
    {
        try {
            $this->conn()->executeQuery('SELECT 1');
            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    #[\Override]
    public function setDatabase(string $database): void
    {
        $this->config['database'] = $database;
        $this->connection?->close();
        $this->connection = DriverManager::getConnection($this->toDbalParams());
    }

    #[\Override]
    public function disconnect(): void
    {
        $this->connection?->close();
        $this->connection = null;
    }

    #[\Override]
    public function getConfig(): array
    {
        return $this->config;
    }
}
