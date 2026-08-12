<?php

declare(strict_types=1);

namespace Kode\Database\Connection;

use Kode\Database\Connection\Bridge\SymfonyBridge;

/**
 * Symfony 体系数据库连接器（基于 Doctrine DBAL）
 *
 * ORM 无关设计：若项目已安装 doctrine/dbal，则复用 Doctrine 连接管理器；
 * 否则回退到内置 PdoConnection，保证开箱即用。
 */
class SymfonyConnector implements ConnectorInterface
{
    #[\Override]
    public function connect(array $config): mixed
    {
        if (class_exists(\Doctrine\DBAL\DriverManager::class)) {
            try {
                return new SymfonyBridge($config);
            } catch (\Throwable) {
            }
        }

        return new PdoConnection($config);
    }

    #[\Override]
    public function disconnect(mixed $connection): void
    {
        if ($connection instanceof ExecutorInterface) {
            $connection->disconnect();
        }
    }

    #[\Override]
    public function isConnected(mixed $connection): bool
    {
        return $connection instanceof ExecutorInterface && $connection->isConnected();
    }
}
