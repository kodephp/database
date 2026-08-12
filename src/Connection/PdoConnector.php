<?php

declare(strict_types=1);

namespace Kode\Database\Connection;

/**
 * 内置 PDO 连接器
 *
 * 当希望显式使用内置 PDO 执行器（不依赖任何第三方 ORM）时，可设置 driver=pdo。
 */
class PdoConnector implements ConnectorInterface
{
    #[\Override]
    public function connect(array $config): mixed
    {
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
