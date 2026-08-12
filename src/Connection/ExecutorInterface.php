<?php

declare(strict_types=1);

namespace Kode\Database\Connection;

/**
 * 数据库连接执行器契约
 *
 * 所有连接器（Laravel / ThinkPHP / Symfony / Hyperf / 内置 PDO）最终都会返回一个
 * 实现该接口的对象，供 QueryBuilder / Connection 调用。
 * 这样无论开发者安装何种 ORM，kode/database 都能以统一方式执行 SQL。
 */
interface ExecutorInterface
{
    /**
     * 执行查询，返回结果集
     *
     * @param string $sql SQL 语句
     * @param array $bindings 参数绑定
     * @return array<int, array<string, mixed>>
     */
    public function select(string $sql, array $bindings = []): array;

    /**
     * 执行插入，返回最后插入的自增 ID
     *
     * @param string $sql SQL 语句
     * @param array $bindings 参数绑定
     * @return int|string
     */
    public function insert(string $sql, array $bindings = []): int|string;

    /**
     * 执行更新，返回受影响行数
     *
     * @param string $sql SQL 语句
     * @param array $bindings 参数绑定
     * @return int
     */
    public function update(string $sql, array $bindings = []): int;

    /**
     * 执行删除，返回受影响行数
     *
     * @param string $sql SQL 语句
     * @param array $bindings 参数绑定
     * @return int
     */
    public function delete(string $sql, array $bindings = []): int;

    /**
     * 执行 DDL / 通用语句
     *
     * @param string $sql SQL 语句
     * @return bool
     */
    public function statement(string $sql): bool;

    /**
     * 开启事务
     */
    public function beginTransaction(): void;

    /**
     * 提交事务
     */
    public function commit(): void;

    /**
     * 回滚事务
     */
    public function rollBack(): void;

    /**
     * 连接是否存活
     */
    public function isConnected(): bool;

    /**
     * 切换到指定数据库（需要时重连）
     */
    public function setDatabase(string $database): void;

    /**
     * 断开连接
     */
    public function disconnect(): void;

    /**
     * 返回连接配置
     *
     * @return array<string, mixed>
     */
    public function getConfig(): array;
}
