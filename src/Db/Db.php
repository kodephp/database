<?php

declare(strict_types=1);

namespace Kode\Database\Db;

use Kode\Database\Connection\ConnectionFactory;
use Kode\Database\Pool\PoolManager;
use Kode\Database\Query\QueryBuilder;

/**
 * 数据库静态代理类
 * 兼容 Webman 的 Db::table() 静态调用方式
 * 支持连接池管理
 */
class Db
{
    protected static ?ConnectionFactory $factory = null;
    protected static array $config = [];

    /**
     * 初始化配置
     */
    public static function setConfig(array $config): void
    {
        self::$config = $config;
        self::$factory = new ConnectionFactory();

        if (isset($config['pool'])) {
            PoolManager::init($config, $config['driver'] ?? 'default');
        }
    }

    /**
     * 获取配置
     */
    public static function getConfig(): array
    {
        return self::$config;
    }

    /**
     * 获取查询构建器（静态调用方式）
     *
     * @example Db::table('users')->select()->get()
     */
    public static function table(string $table): QueryBuilder
    {
        $connection = self::getConnection();
        return (new QueryBuilder($connection))->from($table);
    }

    /**
     * 执行 SQL 查询
     *
     * @example Db::select('SELECT * FROM users WHERE id = ?', [1])
     */
    public static function select(string $sql, array $bindings = []): array
    {
        $connection = self::getConnection();
        return $connection->select($sql, $bindings);
    }

    /**
     * 执行插入
     *
     * @example Db::insert('INSERT INTO users (name) VALUES (?)', ['test'])
     */
    public static function insert(string $sql, array $bindings = []): bool
    {
        $connection = self::getConnection();
        return $connection->insert($sql, $bindings);
    }

    /**
     * 执行更新
     *
     * @example Db::update('UPDATE users SET name = ? WHERE id = ?', ['test', 1])
     */
    public static function update(string $sql, array $bindings = []): int
    {
        $connection = self::getConnection();
        return $connection->update($sql, $bindings);
    }

    /**
     * 执行删除
     *
     * @example Db::delete('DELETE FROM users WHERE id = ?', [1])
     */
    public static function delete(string $sql, array $bindings = []): int
    {
        $connection = self::getConnection();
        return $connection->delete($sql, $bindings);
    }

    /**
     * 执行语句
     *
     * @example Db::statement('DROP TABLE IF EXISTS users')
     */
    public static function statement(string $sql): bool
    {
        $connection = self::getConnection();
        return $connection->statement($sql);
    }

    /**
     * 开启事务
     */
    public static function beginTransaction(): void
    {
        $connection = self::getConnection();
        $connection->beginTransaction();
    }

    /**
     * 提交事务
     */
    public static function commit(): void
    {
        $connection = self::getConnection();
        $connection->commit();
    }

    /**
     * 回滚事务
     */
    public static function rollback(): void
    {
        $connection = self::getConnection();
        $connection->rollBack();
    }

    /**
     * 事务执行
     *
     * @example Db::transaction(function () { return Db::table('users')->find(1); })
     */
    public static function transaction(callable $callback): mixed
    {
        self::beginTransaction();
        try {
            $result = $callback();
            self::commit();
            return $result;
        } catch (\Throwable $e) {
            self::rollback();
            throw $e;
        }
    }

    /**
     * 获取连接
     */
    protected static function getConnection(): mixed
    {
        if (!empty(self::$config['pool'])) {
            return PoolManager::getConnection(self::$config['driver'] ?? 'default');
        }

        if (self::$factory === null) {
            self::$factory = new ConnectionFactory();
        }

        return self::$factory->make(self::$config);
    }

    /**
     * 清除连接池
     */
    public static function clearPool(): void
    {
        PoolManager::clear();
    }
}
