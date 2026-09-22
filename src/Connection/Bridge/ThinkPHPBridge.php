<?php

declare(strict_types=1);

namespace Kode\Database\Connection\Bridge;

use Kode\Database\Connection\ExecutorInterface;
use Kode\Database\Connection\QueryObservation;
use think\facade\Db;

/**
 * ThinkPHP ORM 桥接器
 *
 * 复用项目既有的 think\facade\Db 连接管理器，与 ThinkPHP ORM 体系融合。
 * 当 ThinkPHP 未初始化时，连接器自动回退到内置 PdoConnection。
 *
 * 查询观测走 {@see QueryObservation}，与内置 PDO 执行器同一条口径。
 */
class ThinkPHPBridge implements ExecutorInterface
{
    use QueryObservation;

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
        return $this->observe($sql, $bindings, fn (string $sql, array $bindings): array => $this->conn()->query($sql, $bindings));
    }

    #[\Override]
    public function insert(string $sql, array $bindings = []): int|string
    {
        return $this->observe($sql, $bindings, function (string $sql, array $bindings): int|string {
            $connection = $this->conn();
            $connection->execute($sql, $bindings);

            return $connection->getLastInsID();
        });
    }

    #[\Override]
    public function update(string $sql, array $bindings = []): int
    {
        return $this->observe($sql, $bindings, fn (string $sql, array $bindings): int => $this->conn()->execute($sql, $bindings));
    }

    #[\Override]
    public function delete(string $sql, array $bindings = []): int
    {
        return $this->observe($sql, $bindings, fn (string $sql, array $bindings): int => $this->conn()->execute($sql, $bindings));
    }

    #[\Override]
    public function statement(string $sql, array $bindings = []): bool
    {
        return $this->observe($sql, $bindings, function (string $sql, array $bindings): bool {
            $this->conn()->execute($sql, $bindings);

            return true;
        });
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
