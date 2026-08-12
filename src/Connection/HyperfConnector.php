<?php

declare(strict_types=1);

namespace Kode\Database\Connection;

use Kode\Database\Connection\Bridge\HyperfBridge;

/**
 * Hyperf 数据库连接器
 *
 * ORM 无关设计：若项目已安装并初始化 hyperf/database，则复用其连接管理器；
 * 否则回退到内置 PdoConnection，保证开箱即用。
 */
class HyperfConnector implements ConnectorInterface
{
    #[\Override]
    public function connect(array $config): mixed
    {
        if (class_exists(\Hyperf\DbConnection\Db::class)) {
            try {
                return new HyperfBridge($config);
            } catch (\Throwable) {
            }
        }

        $config['database_driver'] = \Kode\Database\Config\Driver::dbTypeFromConfig($config)->value;
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
