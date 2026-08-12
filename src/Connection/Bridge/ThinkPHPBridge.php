<?php

declare(strict_types=1);

namespace Kode\Database\Connection\Bridge;

use Kode\Database\Connection\ExecutorInterface;
use think\facade\Db;

/**
 * ThinkPHP ORM 桥接器
 *
 * 复用项目既有的 think\facade\Db 连接管理器，与 ThinkPHP ORM 体系融合。
 * 当 ThinkPHP 未初始化时，连接器自动回退到内置 PdoConnection。
 */
class ThinkPHPBridge implements ExecutorInterface
{
    public function __construct(protected array $config = [])
    {
    }

    protected function conn(): object
    {
        return Db::connect($this->config['connection'] ?? null);
    }

    #[\Override]
    public function select(string $sql, array $bindings = []): array
    {
        return $this->conn()->query($sql, $bindings);
    }

    #[\Override]
    public function insert(string $sql, array $bindings = []): int|string
    {
        $this->conn()->execute($sql, $bindings);
        return $this->conn()->getLastInsID();
    }

    #[\Override]
    public function update(string $sql, array $bindings = []): int
    {
        return $this->conn()->execute($sql, $bindings);
    }

    #[\Override]
    public function delete(string $sql, array $bindings = []): int
    {
        return $this->conn()->execute($sql, $bindings);
    }

    #[\Override]
    public function statement(string $sql): bool
    {
        $this->conn()->execute($sql);
        return true;
    }

    #[\Override]
    public function beginTransaction(): void
    {
        $this->conn()->startTrans();
    }

    #[\Override]
    public function commit(): void
    {
        $this->conn()->commit();
    }

    #[\Override]
    public function rollBack(): void
    {
        $this->conn()->rollback();
    }

    #[\Override]
    public function isConnected(): bool
    {
        try {
            return $this->conn()->getPdo() !== null;
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
        $this->conn()->close();
    }

    #[\Override]
    public function getConfig(): array
    {
        return $this->config;
    }
}
