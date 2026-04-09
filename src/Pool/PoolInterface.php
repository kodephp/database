<?php

declare(strict_types=1);

namespace Kode\Database\Pool;

use Kode\Database\Connection\ConnectorInterface;
use Kode\Database\Exception\ConnectionException;

interface PoolInterface
{
    /**
     * 获取连接
     */
    public function get(): mixed;

    /**
     * 归还连接
     */
    public function release(mixed $connection): void;

    /**
     * 获取连接器
     */
    public function getConnector(): ConnectorInterface;

    /**
     * 获取配置
     */
    public function getConfig(): array;
}
