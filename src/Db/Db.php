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
 * 支持多数据库连接、分库分表、读写分离自动路由
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

    /** @var string|null 读写分离从库连接名 */
    protected static ?string $readConnection = null;

    /** @var bool 是否启用读写分离 */
    protected static bool $readWriteSplit = false;

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
     * 启用读写分离
     * 自动将查询路由到从库，写操作路由到主库
     *
     * @param string $readConnection 从库连接名
     * @return void
     * @example Db::enableReadWriteSplit('slave')
     */
    public static function enableReadWriteSplit(string $readConnection): void
    {
        self::$readWriteSplit = true;
        self::$readConnection = $readConnection;
    }

    /**
     * 禁用读写分离
     */
    public static function disableReadWriteSplit(): void
    {
        self::$readWriteSplit = false;
        self::$readConnection = null;
    }

    /**
     * 判断是否为读操作
     */
    protected static function isReadOperation(): bool
    {
        return true;
    }

    /**
     * 获取写连接（主库）
     */
    protected static function getWriteConnection(): mixed
    {
        return self::getConnection();
    }

    /**
     * 获取读连接（从库）
     */
    protected static function getReadConnection(): mixed
    {
        if (!self::$readWriteSplit || self::$readConnection === null) {
            return self::getConnection();
        }

        return self::getConnection(self::$readConnection);
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
     * 获取查询构建器（静态调用方式，自动读写分离）
     *
     * @example Db::table('users')->select()->get()
     */
    public static function table(string $table): QueryBuilder
    {
        $connection = self::getReadConnection();
        $builder = new QueryBuilder($connection);
        return $builder->from($table);
    }

    /**
     * 获取写查询构建器（强制使用主库）
     *
     * @example Db::tableWrite('users')->insert([...])
     */
    public static function tableWrite(string $table): QueryBuilder
    {
        $connection = self::getWriteConnection();
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
        $connection = self::getReadConnection();
        return $connection->select($sql, $bindings);
    }

    /**
     * 执行插入
     *
     * @example Db::insert('INSERT INTO users (name) VALUES (?)', ['test'])
     */
    public static function insert(string $sql, array $bindings = []): bool
    {
        $connection = self::getWriteConnection();
        return $connection->insert($sql, $bindings);
    }

    /**
     * 执行更新
     *
     * @example Db::update('UPDATE users SET name = ? WHERE id = ?', ['test', 1])
     */
    public static function update(string $sql, array $bindings = []): int
    {
        $connection = self::getWriteConnection();
        return $connection->update($sql, $bindings);
    }

    /**
     * 执行删除
     *
     * @example Db::delete('DELETE FROM users WHERE id = ?', [1])
     */
    public static function delete(string $sql, array $bindings = []): int
    {
        $connection = self::getWriteConnection();
        return $connection->delete($sql, $bindings);
    }

    /**
     * 执行语句
     *
     * @example Db::statement('DROP TABLE IF EXISTS users')
     */
    public static function statement(string $sql): bool
    {
        $connection = self::getWriteConnection();
        return $connection->statement($sql);
    }

    /**
     * 开启事务
     */
    public static function beginTransaction(): void
    {
        $connection = self::getWriteConnection();
        $connection->beginTransaction();
    }

    /**
     * 提交事务
     */
    public static function commit(): void
    {
        $connection = self::getWriteConnection();
        $connection->commit();
    }

    /**
     * 回滚事务
     */
    public static function rollback(): void
    {
        $connection = self::getWriteConnection();
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
    public static function clearPool(): void
    {
        PoolManager::clear();
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
     */
    public static function reconnect(): void
    {
        PoolManager::clear();
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

    /**
     * 批量插入（优化版）- 支持 chunk 分段插入
     *
     * @param string $table 表名
     * @param array $records 记录数组
     * @param int $chunkSize 每批插入数量
     * @return int 总插入行数
     * @example Db::table('users')->chunkInsert(['name' => 'a'], ['name' => 'b'], ...)
     */
    public static function chunkInsert(string $table, array $records, int $chunkSize = 1000): int
    {
        $totalInserted = 0;
        $chunks = array_chunk($records, $chunkSize);

        foreach ($chunks as $chunk) {
            $builder = self::tableWrite($table);
            if ($builder->insertAll($chunk)) {
                $totalInserted += count($chunk);
            }
        }

        return $totalInserted;
    }

    /**
     * Upsert - 插入或更新（基于唯一键）
     *
     * @param string $table 表名
     * @param array $data 插入/更新数据
     * @param array $uniqueKeys 唯一键列表
     * @param array $updateKeys 更新时更新的字段（默认全部）
     * @return bool
     * @example Db::upsert('users', ['email' => 'test@example.com', 'name' => 'test'], ['email'])
     */
    public static function upsert(string $table, array $data, array $uniqueKeys, array $updateKeys = []): bool
    {
        if (empty($data) || empty($uniqueKeys)) {
            return false;
        }

        $whereConditions = [];
        foreach ($uniqueKeys as $key) {
            if (isset($data[$key])) {
                $whereConditions[] = "{$key} = ?";
            }
        }

        $bindings = [];
        foreach ($uniqueKeys as $key) {
            if (isset($data[$key])) {
                $bindings[] = $data[$key];
            }
        }

        $exists = self::table($table)
            ->whereRaw(implode(' AND ', $whereConditions), $bindings)
            ->exists();

        if ($exists) {
            $where = [];
            foreach ($uniqueKeys as $key) {
                if (isset($data[$key])) {
                    $where[$key] = $data[$key];
                }
            }

            $updateData = empty($updateKeys) ? $data : array_intersect_key($data, array_flip($updateKeys));

            if (!empty($updateData)) {
                return self::tableWrite($table)->where($where)->update($updateData) > 0;
            }
            return false;
        }

        return self::tableWrite($table)->insert($data);
    }

    /**
     * 模型静态调用代理
     * 支持 User::find(1) 形式调用
     *
     * @param string $method 方法名
     * @param array $arguments 参数
     * @return mixed
     */
    public static function __callStatic(string $method, array $arguments): mixed
    {
        throw new \BadMethodCallException("请创建 Model 类后使用静态方法调用");
    }

    /**
     * 直接执行批量 SQL
     *
     * @param array $sqls SQL数组
     * @return array 结果数组
     */
    public static function batch(array $sqls): array
    {
        $results = [];
        foreach ($sqls as $key => $sql) {
            if (is_string($sql)) {
                $results[$key] = self::select($sql);
            } elseif (is_array($sql) && count($sql) >= 2) {
                $results[$key] = self::select($sql[0], $sql[1] ?? []);
            }
        }
        return $results;
    }

    /**
     * 批量执行写入
     *
     * @param string $table 表名
     * @param array $records 记录数组
     * @param int $chunkSize 分块大小
     * @return int 成功数量
     */
    public static function batchInsert(string $table, array $records, int $chunkSize = 1000): int
    {
        return self::chunkInsert($table, $records, $chunkSize);
    }

    /**
     * 批量执行更新
     *
     * @param string $table 表名
     * @param array $data 更新数据数组
     * @param string $field 判断字段
     * @return int 成功数量
     */
    public static function batchUpdate(string $table, array $data, string $field = 'id'): int
    {
        if (empty($data)) {
            return 0;
        }

        $affected = 0;
        foreach ($data as $record) {
            if (!isset($record[$field])) {
                continue;
            }

            $id = $record[$field];
            unset($record[$field]);

            if (!empty($record)) {
                $affected += self::tableWrite($table)
                    ->where($field, '=', $id)
                    ->update($record);
            }
        }

        return $affected;
    }

    /**
     * 清空表
     *
     * @param string $table 表名
     * @return bool
     */
    public static function truncate(string $table): bool
    {
        return self::statement("TRUNCATE TABLE {$table}");
    }

    /**
     * 获取数据库版本
     *
     * @return string
     */
    public static function getVersion(): string
    {
        $result = self::select('SELECT VERSION() as version');
        return $result[0]['version'] ?? 'unknown';
    }

    /**
     * 检查表是否存在
     *
     * @param string $table 表名
     * @return bool
     */
    public static function tableExists(string $table): bool
    {
        $config = self::$config;
        $database = $config['database'] ?? '';
        $result = self::select(
            "SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?",
            [$database, $table]
        );
        return !empty($result);
    }

    /**
     * 获取表结构
     *
     * @param string $table 表名
     * @return array
     */
    public static function getTableColumns(string $table): array
    {
        $config = self::$config;
        $database = $config['database'] ?? '';
        return self::select(
            "SELECT COLUMN_NAME, DATA_TYPE, IS_NULLABLE, COLUMN_DEFAULT, COLUMN_KEY, EXTRA FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? ORDER BY ORDINAL_POSITION",
            [$database, $table]
        );
    }

    /**
     * 表达式查询
     *
     * @param string $expression SQL 表达式
     * @param array $bindings 参数
     * @return array
     */
    public static function raw(string $expression, array $bindings = []): array
    {
        return self::select($expression, $bindings);
    }

    /**
     * 获取最后执行的 SQL
     *
     * @return string
     */
    public static function getLastSql(): string
    {
        return self::$lastSql ?? '';
    }

    /** @var string|null 最后执行的 SQL */
    protected static ?string $lastSql = null;

    /** @var bool 是否启用查询日志 */
    protected static bool $queryLogEnabled = false;

    /** @var array 查询日志 */
    protected static array $queryLogs = [];

    /**
     * 获取所有表名
     *
     * @return array
     */
    public static function getTables(): array
    {
        $config = self::$config;
        $database = $config['database'] ?? '';
        $result = self::select(
            "SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_TYPE = 'BASE TABLE'",
            [$database]
        );
        return array_column($result, 'TABLE_NAME');
    }

    /**
     * 获取所有视图名
     *
     * @return array
     */
    public static function getViews(): array
    {
        $config = self::$config;
        $database = $config['database'] ?? '';
        $result = self::select(
            "SELECT TABLE_NAME FROM INFORMATION_SCHEMA.VIEWS WHERE TABLE_SCHEMA = ?",
            [$database]
        );
        return array_column($result, 'TABLE_NAME');
    }

    /**
     * 获取所有数据库名
     *
     * @return array
     */
    public static function getDatabases(): array
    {
        $result = self::select("SELECT SCHEMA_NAME as Database FROM INFORMATION_SCHEMA.SCHEMATA");
        return array_column($result, 'Database');
    }

    /**
     * 获取表索引信息
     *
     * @param string $table 表名
     * @return array
     */
    public static function getIndexes(string $table): array
    {
        $config = self::$config;
        $database = $config['database'] ?? '';
        return self::select(
            "SELECT INDEX_NAME, COLUMN_NAME, NON_UNIQUE FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? ORDER BY INDEX_NAME, SEQ_IN_INDEX",
            [$database, $table]
        );
    }

    /**
     * 获取表外键信息
     *
     * @param string $table 表名
     * @return array
     */
    public static function getForeignKeys(string $table): array
    {
        $config = self::$config;
        $database = $config['database'] ?? '';
        return self::select(
            "SELECT CONSTRAINT_NAME, COLUMN_NAME, REFERENCED_TABLE_NAME, REFERENCED_COLUMN_NAME FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND REFERENCED_TABLE_NAME IS NOT NULL",
            [$database, $table]
        );
    }

    /**
     * 执行 SQL 文件
     *
     * @param string $filePath SQL 文件路径
     * @return array 执行结果
     */
    public static function executeFile(string $filePath): array
    {
        if (!file_exists($filePath)) {
            return ['success' => false, 'error' => 'File not found: ' . $filePath];
        }

        $sql = file_get_contents($filePath);
        $statements = array_filter(array_map('trim', explode(';', $sql)));

        $results = [];
        foreach ($statements as $statement) {
            if (empty($statement)) {
                continue;
            }
            try {
                self::statement($statement);
                $results[] = ['sql' => substr($statement, 0, 50) . '...', 'success' => true];
            } catch (\Throwable $e) {
                $results[] = ['sql' => substr($statement, 0, 50) . '...', 'success' => false, 'error' => $e->getMessage()];
            }
        }

        return $results;
    }

    /**
     * 重建表
     *
     * @param string $table 表名
     * @return bool
     */
    public static function rebuildTable(string $table): bool
    {
        try {
            self::statement("REPAIR TABLE {$table}");
            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * 优化表
     *
     * @param string $table 表名
     * @return bool
     */
    public static function optimizeTable(string $table): bool
    {
        try {
            self::statement("OPTIMIZE TABLE {$table}");
            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * 分析表
     *
     * @param string $table 表名
     * @return bool
     */
    public static function analyzeTable(string $table): bool
    {
        try {
            self::statement("ANALYZE TABLE {$table}");
            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * 检查表状态
     *
     * @param string $table 表名
     * @return array|null
     */
    public static function getTableStatus(string $table): ?array
    {
        $config = self::$config;
        $database = $config['database'] ?? '';
        $result = self::select(
            "SELECT TABLE_NAME, ENGINE, TABLE_ROWS, DATA_LENGTH, INDEX_LENGTH, AUTO_INCREMENT FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?",
            [$database, $table]
        );
        return $result[0] ?? null;
    }

    /**
     * 获取数据库大小
     *
     * @return int bytes
     */
    public static function getDatabaseSize(): int
    {
        $config = self::$config;
        $database = $config['database'] ?? '';
        $result = self::select(
            "SELECT SUM(DATA_LENGTH + INDEX_LENGTH) as size FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = ?",
            [$database]
        );
        return (int) ($result[0]['size'] ?? 0);
    }

    /**
     * 获取表大小
     *
     * @param string $table 表名
     * @return int bytes
     */
    public static function getTableSize(string $table): int
    {
        $status = self::getTableStatus($table);
        if ($status) {
            return (int) ($status['DATA_LENGTH'] ?? 0) + (int) ($status['INDEX_LENGTH'] ?? 0);
        }
        return 0;
    }

    /**
     * 格式化字节大小
     *
     * @param int $bytes 字节
     * @return string
     */
    public static function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB', 'PB'];
        $i = 0;
        while ($bytes >= 1024 && $i < count($units) - 1) {
            $bytes /= 1024;
            $i++;
        }
        return round($bytes, 2) . ' ' . $units[$i];
    }

    /**
     * 关闭所有连接
     */
    public static function closeAllConnections(): void
    {
        PoolManager::clear();
    }

    /**
     * 备份表结构
     *
     * @param string $table 表名
     * @return string CREATE TABLE SQL
     */
    public static function backupTable(string $table): string
    {
        $result = self::select("SHOW CREATE TABLE {$table}");
        return $result[0]['Create Table'] ?? '';
    }

    /**
     * 复制表结构
     *
     * @param string $source 源表
     * @param string $target 目标表
     * @return bool
     */
    public static function copyTableStructure(string $source, string $target): bool
    {
        try {
            $createSql = self::backupTable($source);
            $createSql = str_replace($source, $target, $createSql);
            return self::statement($createSql);
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * 重命名表
     *
     * @param string $from 原表名
     * @param string $to 新表名
     * @return bool
     */
    public static function renameTable(string $from, string $to): bool
    {
        return self::statement("RENAME TABLE {$from} TO {$to}");
    }

    /**
     * 清空表（自增归零）
     *
     * @param string $table 表名
     * @return bool
     */
    public static function truncateTable(string $table): bool
    {
        return self::statement("TRUNCATE TABLE {$table}");
    }

    /**
     * 删除表
     *
     * @param string $table 表名
     * @return bool
     */
    public static function dropTable(string $table): bool
    {
        return self::statement("DROP TABLE IF EXISTS {$table}");
    }

    /**
     * 删除多个表
     *
     * @param array $tables 表名数组
     * @return bool
     */
    public static function dropTables(array $tables): bool
    {
        if (empty($tables)) {
            return false;
        }

        $tables = array_map(fn($t) => "`{$t}`", $tables);
        return self::statement("DROP TABLE IF EXISTS " . implode(', ', $tables));
    }

    /**
     * 检查表是否存在
     *
     * @param string $table 表名
     * @return bool
     */
    public static function hasTable(string $table): bool
    {
        $tables = self::tables();
        return in_array($table, $tables, true);
    }

    /**
     * 检查字段是否存在
     *
     * @param string $table 表名
     * @param string $column 字段名
     * @return bool
     */
    public static function hasColumn(string $table, string $column): bool
    {
        $columns = self::columns($table);
        return in_array($column, $columns, true);
    }

    /**
     * 获取所有表名
     *
     * @return array
     */
    public static function tables(): array
    {
        $result = self::select('SHOW TABLES');
        if (empty($result)) {
            return [];
        }
        $key = array_key_first($result[0] ?? []);
        return array_column($result, $key);
    }

    /**
     * 获取表字段信息
     *
     * @param string $table 表名
     * @return array
     */
    public static function columns(string $table): array
    {
        $result = self::select("SHOW COLUMNS FROM {$table}");
        return array_column($result, 'Field');
    }

    /**
     * 获取表索引信息
     *
     * @param string $table 表名
     * @return array
     */
    public static function indexes(string $table): array
    {
        return self::select("SHOW INDEX FROM {$table}");
    }

    /**
     * 获取表主键信息
     *
     * @param string $table 表名
     * @return array
     */
    public static function primaryKeys(string $table): array
    {
        $result = self::select("SHOW INDEX FROM {$table} WHERE Key_name = 'PRIMARY'");
        return array_column($result, 'Column_name');
    }

    /**
     * 设置查询日志
     *
     * @param bool $enabled 是否启用
     */
    public static function enableQueryLog(bool $enabled = true): void
    {
        self::$queryLogEnabled = $enabled;
        if (!$enabled) {
            self::$queryLogs = [];
        }
    }

    /**
     * 获取查询日志
     *
     * @return array
     */
    public static function getQueryLog(): array
    {
        return self::$queryLogs;
    }

    /**
     * 记录查询日志
     *
     * @param string $sql SQL 语句
     * @param array $bindings 参数
     * @param float $time 执行时间
     */
    public static function logQuery(string $sql, array $bindings = [], float $time = 0): void
    {
        if (self::$queryLogEnabled) {
            self::$queryLogs[] = [
                'sql' => $sql,
                'bindings' => $bindings,
                'time' => $time,
                'created_at' => date('Y-m-d H:i:s'),
            ];
        }
    }

    /**
     * 清除查询日志
     */
    public static function clearQueryLog(): void
    {
        self::$queryLogs = [];
    }
}
