<?php

declare(strict_types=1);

namespace Kode\Database\Connection;

/**
 * 连接器接口
 */
interface ConnectorInterface
{
    /**
     * 建立数据库连接
     *
     * @param array $config 连接配置
     * @return mixed
     */
    public function connect(array $config): mixed;

    /**
     * 断开连接
     *
     * @param mixed $connection
     * @return void
     */
    public function disconnect(mixed $connection): void;

    /**
     * 检查连接是否存活
     *
     * @param mixed $connection
     * @return bool
     */
    public function isConnected(mixed $connection): bool;
}
