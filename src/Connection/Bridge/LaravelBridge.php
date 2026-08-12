<?php

declare(strict_types=1);

namespace Kode\Database\Connection\Bridge;

use Kode\Database\Connection\ExecutorInterface;

/**
 * Laravel ORM 桥接器
 *
 * 当项目已安装 illuminate/database（或 laravel/framework）并初始化 Capsule / DB 门面后，
 * 本桥接器复用其连接管理器执行 SQL，从而与项目现有 Laravel ORM 完全融合。
 * 若 Laravel 未初始化，连接器会自动回退到内置 PdoConnection。
 */
class LaravelBridge implements ExecutorInterface
{
    public function __construct(protected array $config = [])
    {
    }

    /**
     * 取得 Laravel 连接实例
     */
    protected function db(): object
    {
        if (class_exists(\Illuminate\Database\Capsule\Manager::class)) {
            return \Illuminate\Database\Capsule\Manager::connection(
                $this->config['connection'] ?? null
            );
        }

        return \Illuminate\Support\Facades\DB::connection(
            $this->config['connection'] ?? null
        );
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
        // Laravel 连接池由框架管理，交由其回收
    }

    #[\Override]
    public function getConfig(): array
    {
        return $this->config;
    }
}
