<?php

declare(strict_types=1);

namespace Kode\Database\Connection;

/**
 * ThinkPHP 数据库连接器适配器
 */
class ThinkPHPConnector implements ConnectorInterface
{
    public function connect(array $config): mixed
    {
        return new class($config) {
            public function __construct(private array $config) {}

            public function getConfig(): array
            {
                return $this->config;
            }
        };
    }

    public function disconnect(mixed $connection): void
    {
        unset($connection);
    }

    public function isConnected(mixed $connection): bool
    {
        return $connection !== null;
    }
}
