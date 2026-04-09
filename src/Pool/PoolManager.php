<?php

declare(strict_types=1);

namespace Kode\Database\Pool;

use Kode\Database\Connection\ConnectorInterface;
use Kode\Database\Exception\ConnectionException;

/**
 * 连接池管理器
 * 支持协程/进程上下文隔离
 */
class PoolManager
{
    protected static array $pools = [];
    protected static array $contextPools = [];
    protected static ?string $driver = 'default';

    /**
     * 初始化连接池
     */
    public static function init(array $config, string $driver = 'default'): void
    {
        self::$driver = $driver;
        self::$pools[$driver] = new ConnectionPool($config, $driver);
    }

    /**
     * 获取连接池
     */
    public static function getPool(?string $driver = null): PoolInterface
    {
        $driver = $driver ?? self::$driver;

        if (!isset(self::$pools[$driver])) {
            throw new ConnectionException("连接池未初始化: {$driver}");
        }

        return self::$pools[$driver];
    }

    /**
     * 获取连接（协程安全）
     */
    public static function getConnection(?string $driver = null): mixed
    {
        $driver = $driver ?? self::$driver;
        $pool = self::getPool($driver);

        if (class_exists(\Fiber::class)) {
            $fiberId = \Fiber::getCurrent()->getId();
            $key = "fiber_{$fiberId}";

            if (!isset(self::$contextPools[$key])) {
                self::$contextPools[$key] = $pool->get();
            }

            return self::$contextPools[$key];
        }

        return $pool->get();
    }

    /**
     * 归还连接（协程安全）
     */
    public static function releaseConnection(mixed $connection, ?string $driver = null): void
    {
        $driver = $driver ?? self::$driver;
        $pool = self::getPool($driver);

        if (class_exists(\Fiber::class)) {
            $fiberId = \Fiber::getCurrent()->getId();
            $key = "fiber_{$fiberId}";
            unset(self::$contextPools[$key]);
        }

        $pool->release($connection);
    }

    /**
     * 清除所有连接池
     */
    public static function clear(): void
    {
        self::$pools = [];
        self::$contextPools = [];
    }
}
