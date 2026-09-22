<?php

declare(strict_types=1);

namespace Kode\Database\Tests;

use InvalidArgumentException;
use Kode\Database\Connection\PdoConnection;
use Kode\Database\Db\Connection as DbConnection;
use Kode\Database\Db\Db;
use Kode\Database\Event\EventManager;
use Kode\Database\Event\SqlEvent;
use Kode\Database\Tests\Support\RecordingListener;
use Kode\Database\Tests\Support\ThrowingListener;
use PDOException;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Throwable;

/**
 * 查询观测接线测试。
 *
 * 背景：包里本来有三套观测 API —— Db::enableQueryLog()、Connection::beforeQuery/afterQuery、
 * Event\*（EventManager/SqlEvent）。它们谁也没被调用过：查询日志恒空、钩子恒不触发、事件从不派发，
 * 「慢查询日志」因此根本无从做（钩子触发处还把普通闭包当成 ['before'=>…] 数组，就算触发也调不到）。
 * 现在三处统一挂在执行器这一个切面上（{@see \Kode\Database\Connection\QueryObservation}），本文件按真实 sqlite 连接逐项验证。
 */
final class QueryObservationTest extends TestCase
{
    private PdoConnection $conn;

    /** 观测异常的去向：不接走的话会直接喷进 PHPUnit 输出，把真正的失败淹掉 */
    private string $obsLog = '';

    protected function setUp(): void
    {
        parent::setUp();

        EventManager::resetInstance();
        DbConnection::clearQueryHooks();
        Db::enableQueryLog(false);
        RecordingListener::$events = [];
        ThrowingListener::reset();

        $this->obsLog = (string) tempnam(sys_get_temp_dir(), 'kode-obs-');
        ini_set('error_log', $this->obsLog);

        $this->conn = new PdoConnection([
            'driver' => 'sqlite',
            'database' => ':memory:',
            'connection_name' => 'sqlite_test',
        ]);
        $this->conn->statement('CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT)');
        $this->conn->statement("INSERT INTO users (name) VALUES ('seed')");
    }

    protected function tearDown(): void
    {
        EventManager::resetInstance();
        DbConnection::clearQueryHooks();
        Db::enableQueryLog(false);
        ini_set('error_log', '');
        @unlink($this->obsLog);

        parent::tearDown();
    }

    // ===================== 查询日志 =====================

    public function test_五类执行入口都会记录查询日志(): void
    {
        Db::enableQueryLog(true);

        $this->conn->select('SELECT * FROM users');
        $this->conn->insert('INSERT INTO users (name) VALUES (?)', ['ada']);
        $this->conn->update('UPDATE users SET name = ? WHERE id = 1', ['bob']);
        $this->conn->delete('DELETE FROM users WHERE id = ?', [1]);
        $this->conn->statement('VACUUM');

        $log = Db::getQueryLog();
        self::assertCount(5, $log);
        self::assertSame(['ada'], $log[1]['bindings']);
        self::assertSame('VACUUM', Db::getLastSql());
        foreach ($log as $entry) {
            // 没有连接名，读写分离/分库下就只能对着一串无主 SQL 猜是哪条链路慢了。
            self::assertSame('sqlite_test', $entry['connection']);
            self::assertIsFloat($entry['time']);
            self::assertGreaterThan(0.0, $entry['time']);
        }
    }

    public function test_无人观测时不留任何记录(): void
    {
        // 观测三处全关：既不建日志、也不因观测改写 lastSql（未观测=未记录）。
        $this->conn->select('SELECT * FROM users');

        self::assertSame([], Db::getQueryLog());
        self::assertSame('', Db::getLastSql());
    }

    public function test_查询日志有条数上限(): void
    {
        Db::enableQueryLog(true);

        $total = Db::QUERY_LOG_LIMIT * 3;
        for ($i = 0; $i < $total; $i++) {
            $this->conn->select('SELECT ' . $i);
        }

        $log = Db::getQueryLog();
        self::assertLessThanOrEqual(Db::QUERY_LOG_LIMIT * 2, count($log), '常驻进程里必须封住上限');
        self::assertStringContainsString('SELECT ' . ($total - 1), $log[count($log) - 1]['sql'], '留下的一定是最近的查询');
        self::assertStringNotContainsString('SELECT 0 ', implode('|', array_column($log, 'sql')), '丢掉的是最旧的');
    }

    public function test_关闭日志时最后一条SQL一并清空(): void
    {
        Db::enableQueryLog(true);
        $this->conn->select('SELECT * FROM users');
        self::assertSame('SELECT * FROM users', Db::getLastSql());

        // 只清数组、留着 lastSql，等于留下一条来历不明的 SQL 让人去错的链路上找问题。
        Db::enableQueryLog(false);
        self::assertSame('', Db::getLastSql());
        self::assertSame([], Db::getQueryLog());

        Db::enableQueryLog(true);
        $this->conn->select('SELECT 1');
        Db::clearQueryLog();
        self::assertSame('', Db::getLastSql());
        self::assertTrue(Db::isQueryLogEnabled(), '清日志不该顺手把开关也关了');
    }

    public function test_有人观测但未开日志时只记最后一条SQL(): void
    {
        // 挂了钩子就会走到 logQuery，但「记不记日志」只看开关：否则装个钩子就把日志数组喂满。
        DbConnection::afterQuery(static function (): void {
        });

        $this->conn->select('SELECT * FROM users');

        self::assertSame([], Db::getQueryLog());
        self::assertSame('SELECT * FROM users', Db::getLastSql());
    }

    // ===================== 查询钩子 =====================

    public function test_前钩子改写后的SQL才是真正执行的那条(): void
    {
        Db::enableQueryLog(true);
        // 「加 trace 注释 / 换表名」这类用法要求改写真的生效，否则钩子看到的和库跑的是两回事。
        DbConnection::beforeQuery(static fn (string $sql): array => [$sql === 'SELECT 1' ? 'SELECT name FROM users' : $sql]);

        $rows = $this->conn->select('SELECT 1');

        self::assertSame([['name' => 'seed']], $rows);
        self::assertSame('SELECT name FROM users', Db::getLastSql());
    }

    public function test_前钩子换参数后按新参数执行(): void
    {
        DbConnection::beforeQuery(static fn (): array => ['SELECT name FROM users WHERE name = ?', ['seed']]);

        $rows = $this->conn->select('SELECT name FROM users WHERE name = ?', ['other']);

        self::assertSame([['name' => 'seed']], $rows);
    }

    public function test_前后钩子按执行顺序触发并带上耗时与结果(): void
    {
        $calls = [];
        DbConnection::beforeQuery(static function (string $sql, array $bindings, object $executor) use (&$calls): void {
            $calls[] = ['before', $sql, $executor::class];
        });
        DbConnection::afterQuery(static function (string $sql, array $bindings, mixed $result, object $executor, float $seconds) use (&$calls): void {
            $calls[] = ['after', $seconds, $result];
        });

        $rows = $this->conn->select('SELECT * FROM users');

        self::assertSame(['before', 'SELECT * FROM users', PdoConnection::class], $calls[0]);
        self::assertSame('after', $calls[1][0]);
        self::assertGreaterThan(0.0, $calls[1][1], 'after 钩子拿到的必须是真实耗时');
        self::assertSame($rows, $calls[1][2]);
    }

    public function test_钩子只对注册的那个连接生效(): void
    {
        $global = 0;
        $elsewhere = 0;
        DbConnection::beforeQuery(static function () use (&$global): void {
            $global++;
        });
        DbConnection::beforeQuery(static function () use (&$elsewhere): void {
            $elsewhere++;
        }, 'pgsql_read');

        $this->conn->select('SELECT 1');

        self::assertSame(1, $global);
        self::assertSame(0, $elsewhere);
    }

    public function test_registerQueryHook_同时登记前后钩子(): void
    {
        $order = [];
        DbConnection::registerQueryHook([
            'before' => static function () use (&$order): void {
                $order[] = 'before';
            },
            'after' => static function () use (&$order): void {
                $order[] = 'after';
            },
        ]);

        $this->conn->select('SELECT 1');

        self::assertSame(['before', 'after'], $order);
    }

    public function test_失败查询的钩子收到原始异常(): void
    {
        $seen = null;
        DbConnection::afterQuery(static function (string $sql, array $bindings, mixed $result) use (&$seen): void {
            $seen = $result;
        });

        try {
            $this->conn->select('SELECT * FROM no_such_table');
        } catch (PDOException) {
            // 预期：观测不该改变业务结果
        }

        self::assertInstanceOf(PDOException::class, $seen);
    }

    // ===================== 事件 =====================

    public function test_事件带真实耗时与连接名(): void
    {
        EventManager::getInstance()->listen(null, RecordingListener::class);

        $this->conn->select('SELECT * FROM users');

        self::assertCount(1, RecordingListener::$events);
        $event = RecordingListener::$events[0];
        self::assertSame('sqlite_test', $event->getConnection());
        self::assertGreaterThan(0.0, $event->getDuration());
        self::assertLessThan(5.0, $event->getDuration());
        self::assertFalse($event->failed());
        self::assertGreaterThan(1_000_000.0, $event->getTime(), 'getTime 是时刻，不是耗时');
    }

    public function test_失败查询也派发带异常的事件(): void
    {
        EventManager::getInstance()->listen(SqlEvent::class, RecordingListener::class);

        try {
            $this->conn->insert('INSERT INTO no_such_table (a) VALUES (?)', [1]);
            self::fail('SQL 错误应当抛出');
        } catch (PDOException $e) {
            self::assertInstanceOf(PDOException::class, $e);
        }

        $event = RecordingListener::$events[0];
        self::assertTrue($event->failed());
        self::assertSame($e, $event->getError());
        self::assertGreaterThan(0.0, $event->getDuration());
    }

    // ===================== 观测自身出错 =====================

    public function test_观测回调抛异常不顶替业务结果(): void
    {
        Db::enableQueryLog(true);
        EventManager::getInstance()->listen(SqlEvent::class, ThrowingListener::class);
        DbConnection::afterQuery(static function (): void {
            throw new RuntimeException('钩子写坏了');
        });

        $rows = $this->conn->select('SELECT * FROM users');
        self::assertCount(1, $rows);
        self::assertSame('seed', $rows[0]['name']);

        // 三个观测点各自独立成段：钩子先炸，日志与监听器照样跑到，两处分别留痕。
        self::assertCount(1, Db::getQueryLog());
        self::assertSame(1, ThrowingListener::$hits);

        $written = (string) file_get_contents($this->obsLog);
        self::assertStringContainsString('查询观测失败', $written);
        self::assertStringContainsString('钩子写坏了', $written);
        self::assertStringContainsString('监听器写坏了', $written);
    }

    public function test_业务异常仍然是业务异常(): void
    {
        EventManager::getInstance()->listen(SqlEvent::class, ThrowingListener::class);

        $this->expectException(PDOException::class);
        $this->conn->select('SELECT * FROM no_such_table');
    }

    // ===================== EventManager 本身 =====================

    public function test_监听器类名写错时立刻报错(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('不存在');
        EventManager::getInstance()->listen(SqlEvent::class, self::class . '\\NoSuchListener');
    }

    public function test_闭包监听器不指定事件名时报错(): void
    {
        $this->expectException(InvalidArgumentException::class);
        EventManager::getInstance()->listen(null, static function (): void {
        });
    }

    public function test_没有监听器时不构造事件(): void
    {
        // hasAny() 为 false 时 observe() 连计时都跳过；这里用抛异常的监听器反向证明「注册过就会被调」。
        self::assertFalse(EventManager::getInstance()->hasAny());
        EventManager::getInstance()->listen(SqlEvent::class, ThrowingListener::class);
        self::assertTrue(EventManager::getInstance()->hasAny());

        ThrowingListener::reset();
        try {
            $this->conn->select('SELECT 1');
        } catch (Throwable) {
            self::fail('观测异常不该冒泡');
        }
        self::assertSame(1, ThrowingListener::$hits);
    }
}
