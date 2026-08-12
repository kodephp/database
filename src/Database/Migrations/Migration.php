<?php

declare(strict_types=1);

namespace Kode\Database\Database\Migrations;

use Kode\Database\Db\Db;
use Kode\Database\Schema\Schema;

/**
 * 迁移基类
 *
 * 继承并实现 up()/down()，在方法内使用 create()/table()/drop() 构建表结构。
 * 框架无关，结合 {@see Migrator} 即可运行迁移并自动记录到 migrations 表。
 *
 * @example
 * class CreateUsersTable extends Migration
 * {
 *     public function up(): void
 *     {
 *         $this->create('users', function (Schema $t) {
 *             $t->id();
 *             $t->string('name');
 *             $t->timestamps();
 *         });
 *     }
 *     public function down(): void
 *     {
 *         $this->drop('users');
 *     }
 * }
 */
abstract class Migration
{
    /** 该迁移所属连接名（null 表示使用默认连接） */
    protected ?string $connection = null;

    /** 迁移批次（由 Migrator 写入） */
    public int $batch = 0;

    /**
     * 执行迁移（建表/改表）
     */
    abstract public function up(): void;

    /**
     * 回滚迁移（删表/还原）
     */
    abstract public function down(): void;

    /**
     * 创建表
     */
    protected function create(string $table, callable $callback): void
    {
        Db::statement(Schema::create($table, $callback));
    }

    /**
     * 修改表
     */
    protected function table(string $table, callable $callback): void
    {
        Db::statement(Schema::table($table, $callback));
    }

    /**
     * 删除表
     */
    protected function drop(string $table): void
    {
        Db::statement(Schema::drop($table));
    }

    /**
     * 执行原生 SQL
     */
    protected function sql(string $sql): void
    {
        Db::statement($sql);
    }

    /**
     * 获取连接名
     */
    public function getConnection(): ?string
    {
        return $this->connection;
    }
}
