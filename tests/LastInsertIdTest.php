<?php

declare(strict_types=1);

namespace Kode\Database\Tests;

use Kode\Database\Connection\PdoConnection;
use PDO;
use PDOException;
use PDOStatement;
use PHPUnit\Framework\TestCase;

/**
 * pgsql 的 `lastInsertId()` = `SELECT lastval()`：INSERT 显式带主键、本会话没跑过
 * nextval 时抛 55000 —— 语句已执行成功，这不是「插入失败」。自 v2.3.2 起
 * `insert()` 对这一形状交回空串，其余异常照旧上抛（判据错放会把真故障吞成空 id）。
 *
 * 接线经真 `insert()` 走一遍（语句与句柄都是替身，不触真实库）：谁把
 * `lastInsertIdSafe()` 从 insert() 里撤掉，这组行为用例即红——只测判据本体挡不住回退。
 */
final class LastInsertIdTest extends TestCase
{
    /** 复刻 pdo_pgsql 真实形状（中英文报错都不会把函数名 lastval 翻译掉）。 */
    private static function lastValError(string $sqlState = '55000', ?string $message = null): PDOException
    {
        $message ??= 'SQLSTATE[55000]: Object not in prerequisite state: 7 错误:  在这个会话中还没有定义lastval';
        $e = new PDOException($message);
        $e->errorInfo = [$sqlState, 7, $message];

        return $e;
    }

    /* ==================== 判据 ==================== */

    public function testTheUndefinedLastValShapeIsRecognised(): void
    {
        self::assertTrue(LastValProbe::undefined(self::lastValError()));
        self::assertTrue(LastValProbe::undefined(self::lastValError(
            '55000',
            'SQLSTATE[55000]: Object not in prerequisite state: lastval is not yet defined in this session'
        )));
    }

    public function testOtherErrorsAreNotMistakenForUndefinedLastVal(): void
    {
        // 唯一键冲突：插入真的失败了，必须继续上抛
        self::assertFalse(LastValProbe::undefined(self::lastValError(
            '23505',
            'SQLSTATE[23505]: duplicate key value violates unique constraint "pk"'
        )));
        // 55000 的另一种语义（权限类）：没有 lastval 字样就不认
        self::assertFalse(LastValProbe::undefined(self::lastValError(
            '55000',
            'SQLSTATE[55000]: must be owner of relation kode_users'
        )));
        // 有 lastval 字样但 SQLSTATE 不是 55000：同样不认
        self::assertFalse(LastValProbe::undefined(self::lastValError(
            '42501',
            'SQLSTATE[42501]: permission denied for lastval probe'
        )));
        // errorInfo 缺失时按消息里的 SQLSTATE[55000] 前缀兜
        self::assertTrue(LastValProbe::undefined(
            new PDOException('SQLSTATE[55000]: Object not in prerequisite state: 7 错误:  在这个会话中还没有定义lastval')
        ));
    }

    /* ==================== 接线：insert() 端到端 ==================== */

    public function testInsertReturnsEmptyStringWhenLastValIsUndefined(): void
    {
        $probe = new LastValProbe(['driver' => 'pgsql'], self::lastValError());
        self::assertSame('', $probe->insert('INSERT INTO probe_t(name) VALUES (?)', ['p_1']));
    }

    public function testInsertPassesTheRealInsertIdThrough(): void
    {
        $probe = new LastValProbe(['driver' => 'pgsql'], null, '42');
        self::assertSame('42', $probe->insert('INSERT INTO probe_t(name) VALUES (?)', ['p_1']));
    }

    public function testInsertStillThrowsOnRealFailures(): void
    {
        $probe = new LastValProbe(
            ['driver' => 'pgsql'],
            self::lastValError('23505', 'SQLSTATE[23505]: duplicate key value violates unique constraint "pk"')
        );
        $this->expectException(PDOException::class);
        $probe->insert('INSERT INTO probe_t(name) VALUES (?)', ['p_1']);
    }

    public function testInsertOnOtherDriversStillThrows(): void
    {
        // 驱动闸门：其它方言的 lastInsertId 不是 lastval，这条放行只属于 pgsql
        $probe = new LastValProbe(['driver' => 'mysql'], self::lastValError());
        $this->expectException(PDOException::class);
        $probe->insert('INSERT INTO probe_t(name) VALUES (?)', ['p_1']);
    }
}

/**
 * 只记录、不执行的语句（形态同 BindingTypesTest 的 RecordingStatement）。
 */
final class LastValStatement extends PDOStatement
{
    public function __construct(public readonly string $sql)
    {
    }

    #[\Override]
    public function bindValue(string|int $param, mixed $value, int $type = PDO::PARAM_STR): bool
    {
        return true;
    }

    #[\Override]
    public function execute(?array $params = null): bool
    {
        return true;
    }
}

/**
 * 句柄替身：只在 lastInsertId() 上表态（prepareStatement 已被探针接管，不会被调到）。
 * 不调 parent::__construct()，因此不会真的连任何数据库。
 */
final class LastValPdo extends PDO
{
    public function __construct(
        private ?PDOException $throw = null,
        private string $id = '0',
    ) {
    }

    #[\Override]
    public function lastInsertId(?string $name = null): string
    {
        if ($this->throw !== null) {
            throw $this->throw;
        }

        return $this->id;
    }
}

/**
 * 探针（形态同 RetryProbe）：语句面换成替身、取 id 只经替身句柄表态，
 * 判据与受保护出口经静态方法暴露给离线断言。
 */
final class LastValProbe extends PdoConnection
{
    private readonly LastValPdo $handle;

    public function __construct(array $config, ?PDOException $throw = null, string $id = '0')
    {
        parent::__construct($config);
        $this->handle = new LastValPdo($throw, $id);
    }

    #[\Override]
    protected function prepareStatement(string $sql): PDOStatement
    {
        return new LastValStatement($sql);
    }

    #[\Override]
    protected function ensureConnected(): PDO
    {
        return $this->handle;
    }

    public static function undefined(PDOException $e): bool
    {
        return self::isLastValUndefined($e);
    }
}
