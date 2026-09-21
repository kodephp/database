<?php

declare(strict_types=1);

namespace Kode\Database\Tests;

use Kode\Database\Connection\ExecutorInterface;

/**
 * 记录型执行器：捕获最后一次执行的 SQL 与绑定，供断言使用
 */
final class FakeExecutor implements ExecutorInterface
{
    public string $lastSql = '';
    public array $lastBindings = [];
    public string $lastMethod = '';

    public function __construct(public string $driver = 'mysql')
    {
    }

    #[\Override]
    public function select(string $sql, array $bindings = []): array
    {
        $this->record('select', $sql, $bindings);
        return [];
    }

    #[\Override]
    public function insert(string $sql, array $bindings = []): int|string
    {
        $this->record('insert', $sql, $bindings);
        return 1;
    }

    #[\Override]
    public function update(string $sql, array $bindings = []): int
    {
        $this->record('update', $sql, $bindings);
        return 7;
    }

    #[\Override]
    public function delete(string $sql, array $bindings = []): int
    {
        $this->record('delete', $sql, $bindings);
        return 7;
    }

    #[\Override]
    public function statement(string $sql, array $bindings = []): bool
    {
        $this->record('statement', $sql, $bindings);
        return true;
    }

    #[\Override]
    public function beginTransaction(): void
    {
    }

    #[\Override]
    public function commit(): void
    {
    }

    #[\Override]
    public function rollBack(): void
    {
    }

    #[\Override]
    public function isConnected(): bool
    {
        return true;
    }

    #[\Override]
    public function setDatabase(string $database): void
    {
    }

    #[\Override]
    public function disconnect(): void
    {
    }

    #[\Override]
    public function getConfig(): array
    {
        return ['driver' => $this->driver];
    }

    public function getDriver(): string
    {
        return $this->driver;
    }

    private function record(string $method, string $sql, array $bindings): void
    {
        $this->lastMethod = $method;
        $this->lastSql = $sql;
        $this->lastBindings = $bindings;
    }
}
