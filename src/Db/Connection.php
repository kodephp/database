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
}
