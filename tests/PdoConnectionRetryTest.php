<?php

declare(strict_types=1);

namespace Kode\Database\Tests;

use PDOException;
use PHPUnit\Framework\TestCase;

final class PdoConnectionRetryTest extends TestCase
{
    /**
     * 只暴露受保护的判定与重连钩子，不触碰真实 PDO 连接
     */
    private static function probe(array $config = []): RetryProbe
    {
        return new RetryProbe($config);
    }

    private static function exception(string $sqlState, int|string $driverCode = 0): PDOException
    {
        $e = new PDOException('test');
        $e->errorInfo = [$sqlState, $driverCode, 'message'];
        return $e;
    }

    public function testConnectionClassSqlStateIsRetryable(): void
    {
        self::assertTrue(RetryProbe::failure(self::exception('08006', '08006')));
        self::assertTrue(RetryProbe::failure(self::exception('08S01', 2006)));
    }

    public function testMysqlGoneAwayDriverCodeIsRetryable(): void
    {
        self::assertTrue(RetryProbe::failure(self::exception('HY000', 2006)));
        self::assertTrue(RetryProbe::failure(self::exception('HY000', 2013)));
    }

    public function testTimeoutIsRetryable(): void
    {
        self::assertTrue(RetryProbe::failure(self::exception('HYT00', 0)));
    }

    public function testConstraintViolationIsNotRetryable(): void
    {
        self::assertFalse(RetryProbe::failure(self::exception('23505', '23505')));
        self::assertFalse(RetryProbe::failure(self::exception('23000', 1062)));
    }

    public function testSyntaxErrorIsNotRetryable(): void
    {
        self::assertFalse(RetryProbe::failure(self::exception('42601', '42601')));
        self::assertFalse(RetryProbe::failure(self::exception('42000', 1064)));
    }

    /* ===================== pgsql 连接故障识别 ===================== */

    /**
     * pdo_pgsql 的所有错误驱动码恒为 7（业务错误也一样），MySQL 那套驱动码在 pgsql
     * 上完全不命中；断链又报成 HY000 而不是 08xxx。判据必须按驱动分流。
     */
    public function testPgsqlDeadConnectionIsRecognised(): void
    {
        // 「server closed the connection unexpectedly」/「no connection to the server」
        self::assertTrue(RetryProbe::failure(self::exception('HY000', 7), 'pgsql'));
        // 服务端 FATAL 一律终结会话：admin_shutdown / crash_shutdown / cannot_connect_now
        foreach (['57P01', '57P02', '57P03'] as $state) {
            self::assertTrue(RetryProbe::failure(self::exception($state, 7), 'pgsql'), $state . ' 应判为连接故障');
        }
    }

    /**
     * 实测同一 pgsql 实例的业务错误 SQLSTATE 全集，逐个确认不落进连接故障。
     * 漏掉一个就会让对应的常规报错白白断连重放一次。
     */
    public function testPgsqlBusinessErrorsAreNotConnectionFailures(): void
    {
        // 元组而非数组字面量键：全数字的 SQLSTATE（22012/23505…）会被 PHP  cast 成 int 键
        $businessStates = [
            ['42P01', 'undefined_table'],
            ['42601', 'syntax_error'],
            ['42703', 'undefined_column'],
            ['22012', 'division_by_zero'],
            ['22P02', 'invalid_text_representation'],
            ['22001', 'string_data_right_truncation'],
            ['23505', 'unique_violation'],
            ['23503', 'foreign_key_violation'],
            ['23502', 'not_null_violation'],
            ['25P02', 'in_failed_sql_transaction'],
            // statement_timeout 取消：重放只会再等一次超时，不是连接故障
            ['57014', 'query_canceled'],
        ];
        foreach ($businessStates as [$state, $name]) {
            self::assertFalse(
                RetryProbe::failure(self::exception($state, 7), 'pgsql'),
                $name . ' (' . $state . ') 不得判为连接故障'
            );
        }
    }

    /**
     * 门控必须是驱动名：pdo_sqlite 把「表不存在/语法错」也报成 HY000（实测驱动码 1），
     * 全局认 HY000 会让 sqlite 每条业务错误都白断连重放一次。
     */
    public function testSqliteBusinessHy000IsNotConnectionFailure(): void
    {
        self::assertFalse(RetryProbe::failure(self::exception('HY000', 1), 'sqlite'));
        // 未指定驱动时保持旧口径（只认 08xxx / 超时 / MySQL 驱动码）
        self::assertFalse(RetryProbe::failure(self::exception('HY000', 7)));
    }

    public function testPgsqlDriverAliasesResolve(): void
    {
        self::assertSame('pgsql', self::probe(['driver' => 'pgsql'])->resolvedDriver());
        self::assertSame('pgsql', self::probe(['driver' => 'postgres'])->resolvedDriver());
        self::assertSame('pgsql', self::probe(['driver' => 'PostgreSQL'])->resolvedDriver());
        // driver 被用作 ORM 连接器选择器时，真实数据库看 database_driver
        self::assertSame('pgsql', self::probe(['driver' => 'pdo', 'database_driver' => 'pgsql'])->resolvedDriver());
        self::assertSame('pgsql', self::probe(['driver' => 'pdo', 'pdo_driver' => 'pgsql'])->resolvedDriver());
        self::assertSame('mysql', self::probe(['driver' => 'mysql'])->resolvedDriver());
        self::assertSame('sqlite', self::probe(['driver' => 'sqlite'])->resolvedDriver());
        // 未知值按 DSN 的 default 分支落 mysql，判定侧必须同口径
        self::assertSame('mysql', self::probe(['driver' => 'pdo'])->resolvedDriver());
        // 完全没配 driver 时同样是 mysql（与 buildDsn 的默认分支一致，否则判定与 DSN 分家）
        self::assertSame('mysql', self::probe([])->resolvedDriver());
    }

    /* ===================== 重连/重放的实际行为 ===================== */

    public function testPgsqlDeadConnectionReconnectsAndReplaysOnce(): void
    {
        $probe = self::probe(['driver' => 'pgsql']);
        $attempts = 0;
        $out = $probe->runRetry(function () use (&$attempts): string {
            $attempts++;
            if ($attempts === 1) {
                throw self::exception('HY000', 7);
            }

            return 'ok';
        });

        self::assertSame('ok', $out);
        self::assertSame(2, $attempts, '连接故障应在新连接上重放一次');
    }

    public function testBusinessErrorIsNeverReplayed(): void
    {
        $probe = self::probe(['driver' => 'pgsql']);
        $attempts = 0;
        try {
            $probe->runRetry(function () use (&$attempts): string {
                $attempts++;
                throw self::exception('42P01', 7);
            });
            self::fail('业务错误必须原样抛出');
        } catch (PDOException) {
            // 预期
        }
        self::assertSame(1, $attempts, '非连接故障不得重放');
    }

    /**
     * 事务中途断链不得重连重放：新连接没有 BEGIN，剩余语句会脱离事务逐条自动提交，
     * 且外层 rollBack() 因 inTransaction() 为 false 而空转 —— 原子单元静默变半提交。
     */
    public function testConnectionLossInsideTransactionIsNotReplayed(): void
    {
        $probe = self::probe(['driver' => 'pgsql']);
        $probe->forceLevel(1);
        $attempts = 0;
        try {
            $probe->runRetry(function () use (&$attempts): string {
                $attempts++;
                throw self::exception('HY000', 7);
            });
            self::fail('事务内断链必须原样抛出，让事务边界失败');
        } catch (PDOException) {
            // 预期
        }
        self::assertSame(1, $attempts, '事务内不得重放');
    }

    /** 至多重试一次：重放后仍失败就直接抛出，不做退避循环 */
    public function testReplayIsBoundedToSingleAttempt(): void
    {
        $probe = self::probe(['driver' => 'pgsql']);
        $attempts = 0;
        try {
            $probe->runRetry(function () use (&$attempts): string {
                $attempts++;
                throw self::exception('HY000', 7);
            });
            self::fail('必须抛出');
        } catch (PDOException) {
            // 预期
        }
        self::assertSame(2, $attempts);
    }

    /* ===================== 断链与事务边界 ===================== */

    /**
     * 断链后的 rollBack 不得抛二次异常：调用方通常在 catch 里回滚，
     * 让 "no connection to the server" 冒泡会盖掉真正的失败原因。
     * 吞掉的同时必须丢弃死句柄，否则下一条语句继续用坏连接。
     */
    public function testRollBackOnDeadConnectionDoesNotMaskOriginalError(): void
    {
        $probe = self::probe(['driver' => 'pgsql']);
        $dead = new DeadHandlePdo();
        $probe->inject($dead);
        $probe->forceLevel(1);

        $probe->rollBack();

        self::assertSame(1, $dead->rollBackCalls, '必须真的尝试回滚（不是被 level 挡掉）');
        self::assertNull($probe->handle(), '死句柄必须被丢弃');
        self::assertSame(0, $probe->level(), '事务层级必须归零');
    }

    /**
     * 回滚成功是热路径：不得顺手把连接和语句缓存扔掉。
     * 「吞掉连接级异常」的实现如果把 disconnect 放在 try/catch 外面，每次正常回滚
     * 都会重建连接 —— 比原 bug 更贵。
     */
    public function testSuccessfulRollBackKeepsTheHandle(): void
    {
        $probe = self::probe(['driver' => 'pgsql']);
        $handle = new DeadHandlePdo();
        $handle->lostOnRollBack = false;
        $probe->inject($handle);
        $probe->forceLevel(1);

        $probe->rollBack();

        self::assertSame(1, $handle->rollBackCalls);
        self::assertSame($handle, $probe->handle(), '回滚成功必须保留连接');
        self::assertSame(0, $probe->level());
    }

    /** 非连接类回滚错误不得被吞（例如约束触发器在回滚时报错） */
    public function testRollBackStillPropagatesBusinessErrors(): void
    {
        $probe = self::probe(['driver' => 'pgsql']);
        $broken = new class extends \PDO {
            public function __construct()
            {
            }

            #[\Override]
            public function inTransaction(): bool
            {
                return true;
            }

            #[\Override]
            public function rollBack(): bool
            {
                $e = new PDOException('business');
                $e->errorInfo = ['42P01', 7, 'x'];
                throw $e;
            }
        };
        $probe->inject($broken);
        $probe->forceLevel(1);

        try {
            $probe->rollBack();
            self::fail('业务类回滚错误必须原样抛出');
        } catch (PDOException $e) {
            self::assertSame('42P01', $e->errorInfo[0] ?? $e->getCode());
        }
        self::assertNotNull($probe->handle(), '非连接故障不得丢弃句柄');
    }

    /** BEGIN 上的非连接类错误必须原样抛出且不递增层级 */
    public function testBeginTransactionPropagatesBusinessErrors(): void
    {
        $probe = self::probe(['driver' => 'pgsql']);
        $broken = new class extends \PDO {
            public function __construct()
            {
            }

            #[\Override]
            public function inTransaction(): bool
            {
                return false;
            }

            #[\Override]
            public function beginTransaction(): bool
            {
                $e = new PDOException('business');
                $e->errorInfo = ['42501', 7, 'x'];
                throw $e;
            }
        };
        $probe->inject($broken);

        try {
            $probe->beginTransaction();
            self::fail('必须抛出');
        } catch (PDOException $e) {
            self::assertSame('42501', $e->errorInfo[0] ?? $e->getCode());
        }
        self::assertSame(0, $probe->level(), 'BEGIN 失败不得留下事务层级');
    }

    /**
     * 断链后的第一个「事务型请求」必须能在 BEGIN 这一步自愈：
     * 事务中间件的第一条语句就是 BEGIN，若 BEGIN 不做断连重连，retryOnce 那套判定
     * 永远轮不到，pgsql 上「一旦断链就再也起不来」会原样复现。
     */
    public function testBeginTransactionReconnectsOnDeadConnection(): void
    {
        $probe = self::probe(['driver' => 'pgsql']);
        $dead = new DeadHandlePdo();
        // 重连后的新句柄用内存 sqlite 顶替：只为验证「换句柄 + 重新 BEGIN」，不连真实服务
        $fresh = new \PDO('sqlite::memory:');
        $probe->handleQueue = [$dead, $fresh];

        $probe->beginTransaction();

        self::assertSame(1, $dead->beginCalls, '先在死句柄上尝试');
        self::assertSame(1, $probe->level(), '重连后 BEGIN 成功，层级正常递增');
        self::assertTrue($fresh->inTransaction(), '事务开在新句柄上');
        $fresh->rollBack();
    }

    /** 嵌套事务（level 已 >0）不重复 BEGIN，也不触发重连 */
    public function testNestedBeginDoesNotTouchPdo(): void
    {
        $probe = self::probe(['driver' => 'pgsql']);
        $dead = new DeadHandlePdo();
        $probe->inject($dead);
        $probe->forceLevel(2);

        $probe->beginTransaction();

        self::assertSame(0, $dead->beginCalls, '嵌套事务不得再发 BEGIN');
        self::assertSame(3, $probe->level(), '只递增层级');
    }
}
