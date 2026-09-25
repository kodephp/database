<?php

declare(strict_types=1);

namespace Kode\Database\Tests;

use Kode\Database\Schema\Column;
use Kode\Database\Schema\Schema;
use PHPUnit\Framework\TestCase;

/**
 * 建表器对 NOT NULL 的表达力。
 *
 * 修复前的形状：`Column::toSql()` 只在 `options['not_null']` 为真时输出 NOT NULL，
 * 而全仓没有任何一处把它写成 true —— `setNullable(false)` 是空操作（只写 true 分支），
 * `Schema::nullable()` 又只有「放宽」一个方向，`['nullable' => true]` 这个键 toSql 根本不读。
 * 结果是：**除了主键，迁移建不出任何 NOT NULL 列**，且作者写的收紧声明被静默丢掉。
 *
 * 应用侧的真实代价：kode_test 的 `database/sql/schema.sql` 是手写的，里面 45 个列写着
 * NOT NULL（时间列还带 DEFAULT NOW()），而同一批表由迁移链建出来时全部可空 ——
 * 「照 schema.sql 装库」和「照迁移装库」得到两座形状不同的库，
 * 派生片表（跟随各自环境的基表）也跟着漂。
 */
final class SchemaNotNullTest extends TestCase
{
    /** @param callable(Schema): void $fn */
    private function ddl(callable $fn, string $table = 't_demo'): string
    {
        return Schema::create($table, $fn, 'pgsql');
    }

    public function test_not_null_is_expressible(): void
    {
        $sql = $this->ddl(function (Schema $t): void {
            $t->string('title', 128)->notNull();
        });

        $this->assertStringContainsString('title varchar(128) NOT NULL', $sql);
    }

    /**
     * 收紧与放宽必须是**一对**：`notNull()` 之后还能 `nullable()` 退回可空。
     * 只修一个方向的话，两个 fluent 会长得一模一样（都只能落一种状态）而没人发现。
     */
    public function test_the_pair_is_symmetric(): void
    {
        $tight = $this->ddl(function (Schema $t): void {
            $t->string('a', 32)->notNull();
        });
        $loose = $this->ddl(function (Schema $t): void {
            $t->string('a', 32)->notNull()->nullable();
        });

        $this->assertStringContainsString('NOT NULL', $tight);
        $this->assertStringNotContainsString('NOT NULL', $loose);
    }

    /**
     * `nullable` 与 `not_null` 两个键说的是同一件事，必须都算数。
     * 此前 `['nullable' => false]` 是死配置：作者以为收紧了，DDL 里那一列仍然可空。
     */
    public function test_both_option_spellings_are_honoured(): void
    {
        $cases = [
            ['nullable' => false, 'not_null' => null, 'expect' => true, 'why' => '只写 nullable=false'],
            ['nullable' => true, 'not_null' => null, 'expect' => false, 'why' => '只写 nullable=true'],
            ['nullable' => false, 'not_null' => true, 'expect' => true, 'why' => '两个键都收紧，不冲突'],
            ['nullable' => false, 'not_null' => false, 'expect' => false, 'why' => '显式 not_null 优先于推导'],
        ];
        foreach ($cases as $case) {
            $nullable = $case['nullable'];
            $notNull = $case['not_null'];
            $expect = $case['expect'];
            $why = $case['why'];
            $options = ['length' => 32];
            $options['nullable'] = $nullable;
            if ($notNull !== null) {
                $options['not_null'] = $notNull;
            }
            $col = new Column('a', 'varchar', $options);
            $col->setDriver('pgsql');

            $this->assertSame(
                $expect,
                str_contains($col->toSql(), 'NOT NULL'),
                "{$why}：DDL 应当" . ($expect ? '' : '不') . '带 NOT NULL，实得 ' . $col->toSql()
            );
        }
    }

    /**
     * 反向对照（也是本版本的兼容承诺）：**默认仍然可空**。
     * 若把默认翻成 NOT NULL，所有存量迁移会一起变严格，插入少带一个字段就从成功变成 23502。
     */
    public function test_plain_columns_stay_nullable(): void
    {
        $sql = $this->ddl(function (Schema $t): void {
            $t->id();
            $t->string('title', 128);
            $t->string('body', 2000);
            $t->integer('age');
            $t->timestamps();
        });

        $this->assertStringNotContainsString('NOT NULL', $sql);
        $this->assertStringContainsString('title varchar(128),', $sql);
        // 主键那条腿仍然自己带 PRIMARY KEY（它一直是非空的唯一来源）
        $this->assertStringContainsString('PRIMARY KEY', $sql);
    }

    public function test_not_null_composes_with_default(): void
    {
        $sql = $this->ddl(function (Schema $t): void {
            $t->string('status', 16)->notNull()->default('pending');
        });

        $this->assertStringContainsString("status varchar(16) NOT NULL DEFAULT 'pending'", $sql);
    }

    /** 无列时两个 fluent 都只能安静地返回自己，而不是 end(false) 上取元素。 */
    public function test_the_fluents_are_no_ops_without_a_column(): void
    {
        $schema = new Schema('t_empty', 'pgsql');
        $this->assertSame($schema, $schema->notNull());
        $this->assertSame($schema, $schema->nullable());
    }

    /**
     * `addColumn($name, $type)` 这条路上的类型词与助手方法是两套词汇：
     * 助手内部传 `varchar`，而调用方（含本类 `table()` 的 @example）写 `string`。
     * `string` 不被 buildType 认时原样落进 DDL —— `ADD x string` 是一句语法错误的建列语句。
     */
    public function test_the_string_type_alias_resolves_to_varchar(): void
    {
        $alter = Schema::table('t_demo', function (Schema $t): void {
            $t->addColumn('phone', 'string', ['length' => 11]);
        }, 'pgsql');

        $this->assertStringContainsString('varchar(11)', $alter);
        $this->assertStringNotContainsString(' string', $alter);

        $create = $this->ddl(function (Schema $t): void {
            $t->column('phone', 'string', ['length' => 11, 'nullable' => false]);
        });
        $this->assertStringContainsString('phone varchar(11) NOT NULL', $create);
    }
}
