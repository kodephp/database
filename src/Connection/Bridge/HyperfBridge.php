<?php

declare(strict_types=1);

namespace Kode\Database\Connection\Bridge;

use Hyperf\DbConnection\Db;
use Kode\Database\Connection\ExecutorInterface;

/**
 * Hyperf ORM 桥接器
 *
 * 复用项目既有的 Hyperf\DbConnection\Db 门面（API 与 Laravel 高度一致），
 * 与 Hyperf ORM 体系融合。当 Hyperf 未初始化时，连接器自动回退到内置 PdoConnection。
 */
class HyperfBridge implements ExecutorInterface
{
    public function __construct(protected array $config = [])
    {
    }

    protected function db(): object
    {
        return Db::connection($this->config['connection'] ?? null);
    }

    #[\Override]
    public function select(string $sql, array $bindings = []): array
    {
        return $this->db()->select($sql, $bindings);
    }

    #[\Override]
    public function insert(string $sql, array $bindings = []): int|string
    {
        $this->db()->insert($sql, $bindings);
        return $this->db()->getPdo()->lastInsertId();
    }

    #[\Override]
    public function update(string $sql, array $bindings = []): int
    {
        return $this->db()->update($sql, $bindings);
    }

    #[\Override]
    public function delete(string $sql, array $bindings = []): int
    {
        return $this->db()->delete($sql, $bindings);
    }

    #[\Override]
    public function statement(string $sql): bool
    {
        return $this->db()->statement($sql);
    }

    #[\Override]
    public function beginTransaction(): void
    {
        $this->db()->beginTransaction();
    }

    #[\Override]
    public function commit(): void
    {
        $this->db()->commit();
    }

    #[\Override]
    public function rollBack(): void
    {
        $this->db()->rollBack();
    }

    #[\Override]
    public function isConnected(): bool
    {
        try {
            $this->db()->select('SELECT 1');
            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    #[\Override]
    public function setDatabase(string $database): void
    {
        $this->config['database'] = $database;
    }

    #[\Override]
    public function disconnect(): void
    {
    }

    #[\Override]
    public function getConfig(): array
    {
        return $this->config;
    }
}
