<?php

declare(strict_types=1);

namespace Kode\Database\Db;

use Kode\Database\Pool\PoolManager;
use Kode\Database\Query\QueryBuilder;

/**
 * 数据库连接封装类
 * 用于指定连接进行数据库操作
 * 支持跨库查询、多数据库连接
 */
class Connection
{
    /** @var string 连接名称 */
    protected string $name;

    /** @var string|null 数据库名 */
    protected ?string $database;

    /** @var QueryBuilder|null 当前查询构建器（用于跨库Join） */
    protected ?QueryBuilder $queryBuilder = null;

    /**
     * 构造函数
     *
     * @param string $name 连接名称
     * @param string|null $database 数据库名
     */
    public function __construct(string $name, ?string $database = null)
    {
        $this->name = $name;
        $this->database = $database;
    }

    /**
     * 获取查询构建器
     *
     * @param string $table 表名
     * @return QueryBuilder
     * @example Db::connection('slave')->table('users')->get()
     */
    public function table(string $table): QueryBuilder
    {
        $connection = $this->getConnection();
        return (new QueryBuilder($connection))->from($table);
    }

    /**
     * 执行 SQL 查询
     *
     * @param string $sql SQL语句
     * @param array $bindings 参数绑定
     * @return array
     */
    public function select(string $sql, array $bindings = []): array
    {
        $connection = $this->getConnection();
        return $connection->select($sql, $bindings);
    }

    /**
     * 执行插入
     *
     * @param string $sql SQL语句
     * @param array $bindings 参数绑定
     * @return bool
     */
    public function insert(string $sql, array $bindings = []): bool
    {
        $connection = $this->getConnection();
        return $connection->insert($sql, $bindings);
    }

    /**
     * 执行更新
     *
     * @param string $sql SQL语句
     * @param array $bindings 参数绑定
     * @return int
     */
    public function update(string $sql, array $bindings = []): int
    {
        $connection = $this->getConnection();
        return $connection->update($sql, $bindings);
    }

    /**
     * 执行删除
     *
     * @param string $sql SQL语句
     * @param array $bindings 参数绑定
     * @return int
     */
    public function delete(string $sql, array $bindings = []): int
    {
        $connection = $this->getConnection();
        return $connection->delete($sql, $bindings);
    }

    /**
     * 执行语句
     *
     * @param string $sql SQL语句
     * @return bool
     */
    public function statement(string $sql): bool
    {
        $connection = $this->getConnection();
        return $connection->statement($sql);
    }

    /**
     * 开启事务
     */
    public function beginTransaction(): void
    {
        $connection = $this->getConnection();
        $connection->beginTransaction();
    }

    /**
     * 提交事务
     */
    public function commit(): void
    {
        $connection = $this->getConnection();
        $connection->commit();
    }

    /**
     * 回滚事务
     */
    public function rollback(): void
    {
        $connection = $this->getConnection();
        $connection->rollBack();
    }

    /**
     * 事务执行
     *
     * @param callable $callback 回调函数
     * @return mixed
     */
    public function transaction(callable $callback): mixed
    {
        $this->beginTransaction();
        try {
            $result = $callback($this);
            $this->commit();
            return $result;
        } catch (\Throwable $e) {
            $this->rollback();
            throw $e;
        }
    }

    /**
     * 获取连接
     */
    public function getConnection(): mixed
    {
        $config = Db::getConfig($this->name);

        if (!empty($config['pool'])) {
            $connection = PoolManager::getConnection($this->name);
        } else {
            $factory = new \Kode\Database\Connection\ConnectionFactory();
            $connection = $factory->make($config);
        }

        if ($this->database !== null) {
            $connection->setDatabase($this->database);
        }

        return $connection;
    }

    /**
     * 获取连接名称
     */
    public function getName(): string
    {
        return $this->name;
    }

    /**
     * 获取数据库名
     */
    public function getDatabase(): ?string
    {
        return $this->database;
    }

    /**
     * 跨库 Join 查询
     * 支持 db.table 格式指定不同数据库的表
     *
     * @param string $table 主表名
     * @param string $dbTable 跨库表，格式: "database.table" 或 "database.table as alias"
     * @param string $first 第一个字段
     * @param string $operator 操作符
     * @param string $second 第二个字段
     * @param string $type Join 类型 (INNER, LEFT, RIGHT)
     * @return $this
     * @example Db::connection('default')->crossJoin('users', 'shop.products', 'users.shop_id', '=', 'shop.id')->get()
     */
    public function crossJoin(string $table, string $dbTable, string $first, string $operator, string $second, string $type = 'INNER'): static
    {
        $this->queryBuilder = $this->table($table);
        $this->queryBuilder->join($dbTable, $first, $operator, $second, $type);
        return $this;
    }

    /**
     * 切换数据库
     *
     * @param string $database 数据库名
     * @return $this
     */
    public function useDatabase(string $database): static
    {
        $this->database = $database;
        return $this;
    }

    /**
     * 获取表名（带数据库前缀）
     *
     * @param string $table 表名
     * @return string
     */
    public function qualify(string $table): string
    {
        if ($this->database !== null) {
            return "`{$this->database}`.`{$table}`";
        }
        return "`{$table}`";
    }

    /**
     * 获取查询构建器（带数据库上下文）
     *
     * @return QueryBuilder
     */
    public function query(): QueryBuilder
    {
        return new QueryBuilder($this->getConnection());
    }

    /**
     * 执行原始查询
     *
     * @param string $sql SQL 语句
     * @param array $bindings 参数
     * @return array
     */
    public function raw(string $sql, array $bindings = []): array
    {
        return $this->select($sql, $bindings);
    }

    /**
     * 获取数据库名
     *
     * @return string|null
     */
    public function getDatabaseName(): ?string
    {
        return $this->database;
    }

    /**
     * 克隆连接（保留配置）
     *
     * @return static
     */
    public function copy(): static
    {
        return new static($this->name, $this->database);
    }

    /**
     * 查询钩子回调
     */
    protected static array $beforeQueryCallbacks = [];
    protected static array $afterQueryCallbacks = [];
    protected static array $queryHooks = [];

    /**
     * 注册查询前钩子
     *
     * @param callable $callback 回调函数，参数: (string $sql, array $bindings, Connection $connection)
     * @param string|null $connectionName 连接名，为 null 表示所有连接
     */
    public static function beforeQuery(callable $callback, ?string $connectionName = null): void
    {
        $key = $connectionName ?? '*';
        if (!isset(self::$beforeQueryCallbacks[$key])) {
            self::$beforeQueryCallbacks[$key] = [];
        }
        self::$beforeQueryCallbacks[$key][] = $callback;
    }

    /**
     * 注册查询后钩子
     *
     * @param callable $callback 回调函数，参数: (string $sql, array $bindings, array $result, Connection $connection)
     * @param string|null $connectionName 连接名，为 null 表示所有连接
     */
    public static function afterQuery(callable $callback, ?string $connectionName = null): void
    {
        $key = $connectionName ?? '*';
        if (!isset(self::$afterQueryCallbacks[$key])) {
            self::$afterQueryCallbacks[$key] = [];
        }
        self::$afterQueryCallbacks[$key][] = $callback;
    }

    /**
     * 注册查询钩子（同时包含前后）
     *
     * @param array $hooks 钩子配置 ['before' => callable, 'after' => callable]
     * @param string|null $connectionName 连接名
     */
    public static function registerQueryHook(array $hooks, ?string $connectionName = null): void
    {
        $key = $connectionName ?? '*';
        if (!isset(self::$queryHooks[$key])) {
            self::$queryHooks[$key] = [];
        }
        self::$queryHooks[$key][] = $hooks;
    }

    /**
     * 触发查询前钩子
     */
    protected function triggerBeforeQuery(string $sql, array $bindings): array
    {
        $sql = trim($sql);
        $bindings = $bindings ?? [];

        $this->executeGlobalHook(self::$beforeQueryCallbacks, $sql, $bindings);

        if ($this->name !== null) {
            $this->executeGlobalHook(self::$beforeQueryCallbacks, $sql, $bindings);
        }

        return [$sql, $bindings];
    }

    /**
     * 触发查询后钩子
     */
    protected function triggerAfterQuery(string $sql, array $bindings, array $result): array
    {
        $this->executeGlobalHook(self::$afterQueryCallbacks, $sql, $bindings, $result);

        if ($this->name !== null) {
            $this->executeGlobalHook(self::$afterQueryCallbacks, $sql, $bindings, $result);
        }

        return $result;
    }

    /**
     * 执行全局钩子
     */
    protected function executeGlobalHook(array &$hooks, string $sql, array $bindings, ?array $result = null): void
    {
        $keys = ['*'];
        if ($this->name !== null) {
            $keys[] = $this->name;
        }

        foreach ($keys as $key) {
            if (!isset($hooks[$key])) {
                continue;
            }

            foreach ($hooks[$key] as $callback) {
                if ($result !== null && isset($callback['after'])) {
                    $callback['after']($sql, $bindings, $result, $this);
                } elseif ($result === null && isset($callback['before'])) {
                    $callback['before']($sql, $bindings, $this);
                }
            }
        }
    }

    /**
     * 获取所有查询前钩子
     */
    public static function getBeforeQueryHooks(?string $connectionName = null): array
    {
        $key = $connectionName ?? '*';
        return self::$beforeQueryCallbacks[$key] ?? [];
    }

    /**
     * 获取所有查询后钩子
     */
    public static function getAfterQueryHooks(?string $connectionName = null): array
    {
        $key = $connectionName ?? '*';
        return self::$afterQueryCallbacks[$key] ?? [];
    }

    /**
     * 清除查询钩子
     */
    public static function clearQueryHooks(?string $connectionName = null): void
    {
        if ($connectionName === null) {
            self::$beforeQueryCallbacks = [];
            self::$afterQueryCallbacks = [];
            self::$queryHooks = [];
        } else {
            unset(self::$beforeQueryCallbacks[$connectionName]);
            unset(self::$afterQueryCallbacks[$connectionName]);
            unset(self::$queryHooks[$connectionName]);
        }
    }

    /**
     * 检查连接是否正常
     *
     * @return bool
     */
    public function isConnected(): bool
    {
        try {
            $connection = $this->getConnection();
            return $connection->isConnected();
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Ping 数据库检查连接
     *
     * @return bool
     */
    public function ping(): bool
    {
        try {
            $this->statement('SELECT 1');
            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * 获取所有表名
     *
     * @return array
     */
    public function getTables(): array
    {
        $result = $this->select('SHOW TABLES');
        if (empty($result)) {
            return [];
        }
        $key = array_key_first($result[0]);
        return array_column($result, $key);
    }

    /**
     * 检查表是否存在
     *
     * @param string $table 表名
     * @return bool
     */
    public function tableExists(string $table): bool
    {
        return in_array($table, $this->getTables(), true);
    }

    /**
     * 获取表的所有字段
     *
     * @param string $table 表名
     * @return array
     */
    public function getTableColumns(string $table): array
    {
        $result = $this->select("SHOW COLUMNS FROM {$table}");
        return array_column($result, 'Field');
    }

    /**
     * 获取表的主键字段
     *
     * @param string $table 表名
     * @return array
     */
    public function getPrimaryKey(string $table): array
    {
        $result = $this->select("SHOW INDEX FROM {$table} WHERE Key_name = 'PRIMARY'");
        return array_column($result, 'Column_name');
    }

    /**
     * 获取表的索引信息
     *
     * @param string $table 表名
     * @return array
     */
    public function getIndexes(string $table): array
    {
        return $this->select("SHOW INDEX FROM {$table}");
    }

    /**
     * 获取数据库版本
     *
     * @return string
     */
    public function getVersion(): string
    {
        $result = $this->select('SELECT VERSION() as version');
        return $result[0]['version'] ?? '';
    }

    /**
     * 获取当前数据库名
     *
     * @return string|null
     */
    public function getCurrentDatabase(): ?string
    {
        $result = $this->select('SELECT DATABASE() as db');
        return $result[0]['db'] ?? null;
    }

    /**
     * 重新连接
     *
     * @return bool
     */
    public function reconnect(): bool
    {
        try {
            $config = Db::getConfig($this->name);
            $factory = new \Kode\Database\Connection\ConnectionFactory();
            $connection = $factory->make($config);

            if ($this->database !== null) {
                $connection->setDatabase($this->database);
            }

            return true;
        } catch (\Throwable) {
            return false;
        }
    }
}
