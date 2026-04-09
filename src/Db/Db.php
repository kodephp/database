<?php

declare(strict_types=1);

namespace Kode\Database\Db;

use Kode\Database\Connection\ConnectionFactory;
use Kode\Database\Pool\PoolManager;
use Kode\Database\Query\QueryBuilder;
use Kode\Database\Db\Connection;

/**
 * 数据库静态代理类
 * 兼容 Webman 的 Db::table() 静态调用方式
 * 支持多数据库连接、分库分表
 */
class Db
{
    /** @var ConnectionFactory|null 工厂实例 */
    protected static ?ConnectionFactory $factory = null;

    /** @var array 默认配置 */
    protected static array $config = [];

    /** @var array<string, array> 多数据库配置 */
    protected static array $connections = [];

    /** @var string 默认连接名 */
    protected static string $defaultConnection = 'default';

    /**
     * 初始化默认配置
     */
    public static function setConfig(array $config): void
    {
        self::$config = $config;
        self::$factory = new ConnectionFactory();

        if (isset($config['pool'])) {
            PoolManager::init($config, $config['driver'] ?? self::$defaultConnection);
        }
    }

    /**
     * 添加命名数据库连接
     *
     * @param string $name 连接名称
     * @param array $config 连接配置
     * @example Db::addConnection('slave', ['driver' => 'mysql', 'host' => '127.0.0.1', ...])
     */
    public static function addConnection(string $name, array $config): void
    {
        self::$connections[$name] = $config;

        if (isset($config['pool'])) {
            PoolManager::init($config, $name);
        }
    }

    /**
     * 设置默认连接
     */
    public static function setDefaultConnection(string $name): void
    {
        self::$defaultConnection = $name;
    }

    /**
     * 获取默认连接名
     */
    public static function getDefaultConnection(): string
    {
        return self::$defaultConnection;
    }

    /**
     * 获取配置
     */
    public static function getConfig(?string $name = null): array
    {
        if ($name !== null) {
            return self::$connections[$name] ?? self::$config;
        }
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
        $builder = new QueryBuilder($connection);
        return $builder->from($table);
    }

    /**
     * 指定连接获取查询构建器
     *
     * @example Db::connection('slave')->table('users')->get()
     * @example Db::connection('order_db')->table('orders')->get()
     */
    public static function connection(string $name): Connection
    {
        return new Connection($name);
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
    public static function getConnection(?string $name = null): mixed
    {
        $name = $name ?? self::$defaultConnection;

        if (!empty(self::$connections[$name]['pool'])) {
            return PoolManager::getConnection($name);
        }

        if (!empty(self::$config['pool'])) {
            return PoolManager::getConnection(self::$config['driver'] ?? $name);
        }

        if (self::$factory === null) {
            self::$factory = new ConnectionFactory();
        }

        $config = self::$connections[$name] ?? self::$config;
        return self::$factory->make($config);
    }

    /**
     * 获取原始连接实例（用于高级操作）
     */
    public static function getRawConnection(?string $name = null): mixed
    {
        return self::getConnection($name);
    }

    /**
     * 清除连接池
     */
    public static function clearPool(?string $name = null): void
    {
        if ($name !== null) {
            PoolManager::clear($name);
        } else {
            PoolManager::clear();
        }
    }

    /**
     * 分库分表 - 获取表名（支持按年月分表）
     *
     * @param string $table 表名
     * @param int|string|null $shardingKey 分片键（年份、月份、用户ID等）
     * @return string 实际表名
     * @example Db::table('orders_2024'); // 订单按年分表
     * @example Db::table('logs_' . date('Ym')); // 按年月分表
     */
    public static function getShardingTable(string $table, int|string|null $shardingKey = null): string
    {
        if ($shardingKey === null) {
            return $table;
        }

        return $table . '_' . $shardingKey;
    }

    /**
     * 分库分表 - 表名带后缀
     *
     * @param string $table 表名
     * @param int $suffix 分片后缀（0-99）
     * @return string 实际表名
     * @example Db::tableBySuffix('user_orders', 5); // user_orders_5
     */
    public static function tableBySuffix(string $table, int $suffix): string
    {
        return $table . '_' . $suffix;
    }

    /**
     * 分库分表 - 按哈希分表
     *
     * @param string $table 表名
     * @param int $shardingKey 分片键
     * @param int $shardingCount 分片数量
     * @return string 实际表名
     * @example Db::tableByHash('user_actions', $userId, 16); // 16个分片
     */
    public static function tableByHash(string $table, int $shardingKey, int $shardingCount = 16): string
    {
        $index = $shardingKey % $shardingCount;
        return $table . '_' . $index;
    }

    /**
     * 切换数据库
     *
     * @param string $database 数据库名
     * @return Connection 新的连接实例
     * @example Db::useDatabase('shop')->table('products')->get()
     */
    public static function useDatabase(string $database): Connection
    {
        return new Connection(self::$defaultConnection, $database);
    }

    /**
     * 跨库查询 - 跨库 Join 支持
     * 支持 db.table 格式指定不同数据库的表
     *
     * @param string $table 主表名
     * @param string $dbTable 跨库表，格式: "database.table"
     * @param string $first 第一个字段
     * @param string $operator 操作符
     * @param string $second 第二个字段
     * @param string $type Join 类型 (INNER, LEFT, RIGHT)
     * @return Connection 返回连接实例用于链式调用
     * @example Db::crossJoin('users', 'shop.products', 'users.shop_id', '=', 'shop.id')->get()
     */
    public static function crossJoin(string $table, string $dbTable, string $first, string $operator, string $second, string $type = 'INNER'): Connection
    {
        $connection = new Connection(self::$defaultConnection);
        return $connection->crossJoin($table, $dbTable, $first, $operator, $second, $type);
    }

    /**
     * 获取所有连接配置
     *
     * @return array<string, array>
     */
    public static function getConnections(): array
    {
        return self::$connections;
    }

    /**
     * 检查连接是否存在
     *
     * @param string $name 连接名称
     * @return bool
     */
    public static function hasConnection(string $name): bool
    {
        return isset(self::$connections[$name]) || $name === self::$defaultConnection;
    }

    /**
     * 重新连接
     *
     * @param string|null $name 连接名称
     * @return void
     */
    public static function reconnect(?string $name = null): void
    {
        $name = $name ?? self::$defaultConnection;
        PoolManager::clear($name);
    }

    /**
     * 分库分表路由 - 根据分片键自动选择表
     *
     * @param string $table 表名
     * @param int|string $shardingKey 分片键
     * @param string $strategy 分片策略 (hash, range, suffix)
     * @param int $shardingCount 分片数量 (用于 hash 策略)
     * @param array $rangeMap 范围映射 (用于 range 策略, 格式: ['2024' => 0, '2025' => 4])
     * @return string 实际表名
     * @example Db::routeSharding('orders', 12345, 'hash', 16) // orders_5
     * @example Db::routeSharding('orders', '2024', 'range', 0, ['2024' => 0, '2025' => 4]) // orders_0
     */
    public static function routeSharding(
        string $table,
        int|string $shardingKey,
        string $strategy = 'hash',
        int $shardingCount = 16,
        array $rangeMap = []
    ): string {
        return match ($strategy) {
            'hash' => self::tableByHash($table, (int) $shardingKey, $shardingCount),
            'range' => self::getShardingTable($table, $rangeMap[$shardingKey] ?? $shardingKey),
            'suffix' => self::tableBySuffix($table, (int) $shardingKey),
            default => $table,
        };
    }

    /**
     * 批量分库分表操作 - 跨所有分片查询
     *
     * @param string $table 表前缀
     * @param callable $callback 回调函数，接收 (string $actualTable, int $shardingIndex)
     * @param int $shardingCount 分片数量
     * @param string $strategy 分片策略
     * @return array 汇总所有分片结果
     * @example Db::crossSharding('user_actions', function($table, $index) { return Db::table($table)->where('user_id', $userId)->get(); }, 16)
     */
    public static function crossSharding(
        string $table,
        callable $callback,
        int $shardingCount = 16,
        string $strategy = 'hash'
    ): array {
        $results = [];
        for ($i = 0; $i < $shardingCount; $i++) {
            $actualTable = match ($strategy) {
                'hash' => "{$table}_{$i}",
                'suffix' => "{$table}_{$i}",
                'range' => "{$table}_{$i}",
                default => $table,
            };
            $results[$i] = $callback($actualTable, $i);
        }
        return $results;
    }
}
