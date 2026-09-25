<?php

declare(strict_types=1);

namespace Kode\Database\Tests;

use Kode\Database\Connection\PdoConnection;
use PDO;
use PDOStatement;
use PHPUnit\Framework\TestCase;

/**
 * 绑定值的**类型** —— `PDOStatement::execute($bindings)` 把数组里每个值都按 `PARAM_STR` 送出去，
 * 于是 PHP 的 `false` 到库里是一个空串。
 *
 * 这不是理论问题，而是「关掉一个开关」这个动作必然失败：
 *  - pgsql（实测）：`INSERT ... VALUES (?)` 绑 `false` →
 *    `SQLSTATE[22P02] 无效的类型 boolean 输入语法: ""`，`UPDATE t SET enabled = ?` 同理，
 *    连 `WHERE flag = ?` 绑 `false` 也一样炸（`?::boolean` 对 `''` 直接报错）；
 *  - `true` 侥幸能过：`(string) true === '1'`，而 pgsql 接受 `'1'` 作为 boolean 字面量。
 *    于是这条线只在**写 false 时**露头 —— 「新建能用、关闭就 500」，是最容易被当成业务 bug 的形状。
 *
 * 真实代价（应用侧 `kode_test`）：`Db::table('kode_feature_flags')->update(['enabled' => false])`
 * 即管理端「特性开关」页的 toggle 关闭方向，以及任何 `status`/`enabled` 布尔列置 false 的写入。
 * 端到端的 pgsql 断言在应用侧（`kode_test/tests/QueryBuilderRowShapeTest`），因为包内测试一律离线。
 *
 * sqlite 上同一件事不报错，但**存进去的是一个空串而不是 0**（`typeof(flag)` = `text`），
 * 于是它在这里同样可测 —— 读回来必须还是写进去的那个语义。
 */
final class BindingTypesTest extends TestCase
{
    /** 记录 bindValue/execute 调用的假语句：用于直接看「交给 PDO 的类型是什么」。 */
    private function recorder(): PdoConnection
    {
        return new class(['driver' => 'sqlite', 'database' => ':memory:']) extends PdoConnection {
            /** @var RecordingStatement[] */
            public array $statements = [];

            #[\Override]
            protected function prepareStatement(string $sql): PDOStatement
            {
                $stmt = new RecordingStatement($sql);
                $this->statements[] = $stmt;

                return $stmt;
            }
        };
    }

    private function sqlite(): PdoConnection
    {
        return new PdoConnection(['driver' => 'sqlite', 'database' => ':memory:']);
    }

    /* ==================== 行为：值必须按它本来的语义落地 ==================== */

    public function test_bool_false_reaches_a_boolean_column_as_false(): void
    {
        $conn = $this->sqlite();
        $conn->statement('CREATE TABLE t (id integer, flag BOOLEAN)');
        $conn->insert('INSERT INTO t VALUES (1, ?)', [false]);

        $row = $conn->select('SELECT typeof(flag) AS ty, flag FROM t')[0];

        self::assertNotSame(
            '',
            (string) $row['flag'],
            'false 被送成空串（typeof=' . $row['ty'] . '）：execute() 数组绑定按 PARAM_STR 转值'
        );
        self::assertSame('integer', $row['ty'], '布尔值没有以整数 0 落库，而是被当成文本');
        self::assertSame(0, (int) $row['flag']);
    }

    /**
     * 读侧同样在一条线上：`WHERE flag = ?` 绑 false。
     *
     * 造行必须用**字面量 0/1** 而不是「用同一个坏绑定写进去」—— 两侧都是空串时
     * `'' = ''` 成立，这条断言就变成自证（本版第一稿就是这么过的）。
     */
    public function test_bool_false_works_as_a_where_parameter(): void
    {
        $conn = $this->sqlite();
        $conn->statement('CREATE TABLE w (id integer, flag BOOLEAN)');
        $conn->statement('INSERT INTO w VALUES (1, 1), (2, 0)');

        $hitTrue = (int) $conn->select('SELECT (flag = ?) AS hit FROM w WHERE id = 1', [true])[0]['hit'];
        $hitFalse = (int) $conn->select('SELECT (flag = ?) AS hit FROM w WHERE id = 2', [false])[0]['hit'];

        self::assertSame(1, $hitTrue, '对照组（true 今天就能匹配 1）自己坏了：本用例的结论无从谈起');
        self::assertSame(1, $hitFalse, '按 false 过滤匹配不到库里那个真的 0：绑定被转成了空串');

        $ids = array_column($conn->select('SELECT id FROM w WHERE flag = ? ORDER BY id', [false]), 'id');

        self::assertSame([2], array_map('intval', $ids), '按 false 过滤拿不到那一行');
    }

    /**
     * 语句缓存 × 逐次绑定：同一 SQL 文本的 PDOStatement 会被复用（`STMT_CACHE_LIMIT`），
     * 而 `bindValue()` 的状态是**留在语句上**的（`execute($bindings)` 每次会重置）。
     * 改成显式绑定后，每一次调用都必须重新绑齐所有参数，否则第二次执行读到第一次的值。
     */
    public function test_a_reused_cached_statement_rebinds_every_call(): void
    {
        $conn = $this->sqlite();
        $conn->statement('CREATE TABLE c (id integer, flag BOOLEAN)');
        $sql = 'INSERT INTO c VALUES (?, ?)';
        $conn->insert($sql, [1, true]);
        $conn->insert($sql, [2, false]);
        $conn->insert($sql, [3, true]);

        $rows = $conn->select('SELECT id, typeof(flag) AS ty, flag FROM c ORDER BY id');

        // 断在**存储类型**上而不是 (int) 读出来的数字：`''` 与 `'1'` 取整后是 0 与 1，
        // 只比数值的话这条用例在三处坏绑定下全都「过」（第一稿即如此）。
        self::assertSame(
            ['integer', 'integer', 'integer'],
            array_column($rows, 'ty'),
            '布尔值被当成文本落库了（同一条 SQL 复用缓存语句 × 三次不同的值）'
        );
        self::assertSame([[1, 1], [2, 0], [3, 1]], array_map(
            static fn(array $r): array => [(int) $r['id'], (int) $r['flag']],
            $rows
        ), '复用缓存语句时上一次的绑定残留了');
    }

    /* ==================== 派发：类型是谁决定的 ==================== */

    public function test_param_types_are_declared_to_pdo(): void
    {
        $conn = $this->recorder();
        $conn->statement('SELECT ?, ?, ?, ?, ?', [false, true, null, 'abc', 42]);

        $stmt = $conn->statements[0] ?? null;
        self::assertNotNull($stmt, 'statement() 没有走到 prepareStatement()：本用例没有对象');

        self::assertSame(
            [
                [1, false, PDO::PARAM_BOOL],
                [2, true, PDO::PARAM_BOOL],
                [3, null, PDO::PARAM_NULL],
                [4, 'abc', PDO::PARAM_STR],
                [5, 42, PDO::PARAM_STR],
            ],
            $stmt->bound,
            '交给 PDO 的 (位置, 值, 类型) 与口径不符 —— 只有 bool/null 需要声明，其余必须仍是字符串'
        );
        self::assertTrue($stmt->executedWithoutParams, '仍然把值数组交给 execute()：类型声明等于没写');
    }

    public function test_named_bindings_keep_their_names(): void
    {
        $conn = $this->recorder();
        $conn->statement('UPDATE t SET a = :a, b = :b', [':a' => false, ':b' => 'x']);

        self::assertSame(
            [[':a', false, PDO::PARAM_BOOL], [':b', 'x', PDO::PARAM_STR]],
            $conn->statements[0]->bound,
            '命名参数被按位置重编号了'
        );
    }

    /**
     * 五个执行口（select/insert/update/delete/statement）必须共用同一个绑定派发口。
     * 留一份 `execute($bindings)` 就等于留一条「写 false 必炸」的暗道，
     * 而它只在**其中一张表**上露头。
     *
     * 只扫**代码**：本文件的类注释与 `bindAll()` 的 docblock 都要引用那句旧写法
     * （它们是这段历史的唯一去处），拿全文匹配等于逼着人删解释来让测试变绿。
     */
    public function test_no_statement_path_binds_the_raw_value_array(): void
    {
        $src = self::codeOnly((string) file_get_contents(dirname(__DIR__) . '/src/Connection/PdoConnection.php'));

        self::assertStringNotContainsString(
            'execute($bindings)',
            $src,
            '又有一处把值数组直接交给 execute()：那条线上的 bool 会退化成字符串'
        );
        self::assertStringContainsString('self::bindAll(', $src, '绑定派发口不见了');
    }

    /** 剥掉注释与文档块，只留代码（含字符串字面量，它们本就该被扫）。 */
    private static function codeOnly(string $src): string
    {
        $out = '';
        foreach (token_get_all($src) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            $out .= is_array($token) ? $token[1] : $token;
        }

        return $out;
    }
}

/**
 * 只记录、不执行的语句。
 */
final class RecordingStatement extends PDOStatement
{
    /** @var array<int, array{0: string|int, 1: mixed, 2: int}> */
    public array $bound = [];

    public bool $executedWithoutParams = false;

    public function __construct(public readonly string $sql)
    {
    }

    #[\Override]
    public function bindValue(string|int $param, mixed $value, int $type = PDO::PARAM_STR): bool
    {
        $this->bound[] = [$param, $value, $type];

        return true;
    }

    #[\Override]
    public function execute(?array $params = null): bool
    {
        $this->executedWithoutParams = $params === null || $params === [];

        return true;
    }
}
