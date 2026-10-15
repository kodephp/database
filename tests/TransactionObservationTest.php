<?php

declare(strict_types=1);

namespace Kode\Database\Tests;

use Kode\Database\Connection\Bridge\LaravelBridge;
use Kode\Database\Connection\Bridge\ThinkPHPBridge;
use Kode\Database\Connection\PdoConnection;
use Kode\Database\Event\EventManager;
use Kode\Database\Event\TransactionBeginEvent;
use Kode\Database\Event\TransactionCommitEvent;
use Kode\Database\Event\TransactionRollbackEvent;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * 事务观测接线测试（Begin / Commit / Rollback 事件 + 嵌套 savepoint 语义）。
 *
 * 背景：三个事务事件类长期存在但包内没有任何派发点（README 明示「等后续版本接线」），
 * 且 PdoConnection 的嵌套事务只动计数器不建 savepoint —— 内层回滚是空操作，
 * 外层提交会把「已回滚」的数据一起提交。现在接线点与查询观测同一立场（执行器），
 * 本文件按真实 sqlite 连接验证配对口径、数据语义与故障隔离。
 */
final class TransactionObservationTest extends TestCase
{
    private PdoConnection $conn;

    /** @var list<object> */
    private array $seen = [];

    /** 观测异常的去向：不接走会直接喷进 PHPUnit 输出 */
    private string $obsLog = '';

    protected function setUp(): void
    {
        parent::setUp();

        EventManager::resetInstance();
        $this->seen = [];

        $this->obsLog = (string) tempnam(sys_get_temp_dir(), 'kode-txobs-');
        ini_set('error_log', $this->obsLog);

        $this->conn = new PdoConnection([
            'driver' => 'sqlite',
            'database' => ':memory:',
            'connection_name' => 'tx_test',
        ]);
        $this->conn->statement('CREATE TABLE tx (id INTEGER PRIMARY KEY, name TEXT)');
    }

    protected function tearDown(): void
    {
        EventManager::resetInstance();
        ini_set('error_log', '');
        @unlink($this->obsLog);

        parent::tearDown();
    }

    // ===================== 事件配对口径 =====================

    public function test_最外层begin与commit按层级配对派发(): void
    {
        $this->record();

        $this->conn->beginTransaction();
        $this->conn->commit();

        self::assertSame([
            [TransactionBeginEvent::class, 1],
            [TransactionCommitEvent::class, 1],
        ], $this->signatures());
        self::assertSame('tx_test', $this->seen[0]->getConnection(), '连接名与 SqlEvent 同口径');
    }

    public function test_嵌套事务每层一对begin与关闭事件(): void
    {
        $this->record();

        $this->conn->beginTransaction();
        $this->conn->beginTransaction();
        $this->conn->beginTransaction();
        $this->conn->commit();
        $this->conn->commit();
        $this->conn->commit();

        self::assertSame([
            [TransactionBeginEvent::class, 1],
            [TransactionBeginEvent::class, 2],
            [TransactionBeginEvent::class, 3],
            [TransactionCommitEvent::class, 3],
            [TransactionCommitEvent::class, 2],
            [TransactionCommitEvent::class, 1],
        ], $this->signatures());
    }

    public function test_回滚事件与同层级begin配对(): void
    {
        $this->record();

        $this->conn->beginTransaction();
        $this->conn->beginTransaction();
        $this->conn->rollBack();
        $this->conn->commit();

        self::assertSame([
            [TransactionBeginEvent::class, 1],
            [TransactionBeginEvent::class, 2],
            [TransactionRollbackEvent::class, 2],
            [TransactionCommitEvent::class, 1],
        ], $this->signatures());
    }

    public function test_未跟踪的关闭调用不派发事件(): void
    {
        $this->record();

        // 从未开启事务：level 0 的 commit / rollback 不产生配对噪音
        $this->conn->commit();
        $this->conn->rollBack();

        self::assertSame([], $this->signatures());
    }

    // ===================== savepoint 数据语义 =====================

    public function test_内层回滚撤销本层数据且外层照常提交(): void
    {
        // 关键回归：旧实现内层回滚是空操作，外层提交会把「已回滚」的 inner 一起提交
        $this->conn->beginTransaction();
        $this->conn->insert('INSERT INTO tx (name) VALUES (?)', ['outer']);
        $this->conn->beginTransaction();
        $this->conn->insert('INSERT INTO tx (name) VALUES (?)', ['inner']);
        $this->conn->rollBack();
        $this->conn->commit();

        $rows = $this->conn->select('SELECT name FROM tx ORDER BY id');
        self::assertSame(['outer'], array_column($rows, 'name'), '内层数据必须真正撤销，外层数据保留');
    }

    public function test_内层提交释放本层但外层回滚仍整体撤销(): void
    {
        $this->conn->beginTransaction();
        $this->conn->insert('INSERT INTO tx (name) VALUES (?)', ['a']);
        $this->conn->beginTransaction();
        $this->conn->insert('INSERT INTO tx (name) VALUES (?)', ['b']);
        $this->conn->commit(); // 释放 sp_2：b 不可再独立回滚，但仍属于外层事务
        $this->conn->rollBack();

        $rows = $this->conn->select('SELECT name FROM tx');
        self::assertSame([], $rows, 'RELEASE 不改变归属：外层回滚必须撤销全部改动');
    }

    public function test_多层嵌套的回滚只撤销对应层(): void
    {
        $this->conn->beginTransaction();
        $this->conn->insert('INSERT INTO tx (name) VALUES (?)', ['l1']);
        $this->conn->beginTransaction();
        $this->conn->insert('INSERT INTO tx (name) VALUES (?)', ['l2']);
        $this->conn->beginTransaction();
        $this->conn->insert('INSERT INTO tx (name) VALUES (?)', ['l3']);
        $this->conn->rollBack(); // 撤销 l3
        $this->conn->insert('INSERT INTO tx (name) VALUES (?)', ['l2b']);
        $this->conn->commit();   // 释放 sp_2：l2 + l2b 并入外层
        $this->conn->commit();

        $rows = $this->conn->select('SELECT name FROM tx ORDER BY id');
        self::assertSame(['l1', 'l2', 'l2b'], array_column($rows, 'name'));
    }

    // ===================== 故障隔离 =====================

    public function test_监听器抛异常不影响事务且不外溢(): void
    {
        EventManager::getInstance()->listen(
            TransactionBeginEvent::class,
            static function (): void {
                throw new RuntimeException('监听器写坏了');
            }
        );

        $this->conn->beginTransaction();
        $this->conn->insert('INSERT INTO tx (name) VALUES (?)', ['safe']);
        $this->conn->commit();

        $rows = $this->conn->select('SELECT name FROM tx');
        self::assertSame(['safe'], array_column($rows, 'name'), '事务本身必须完好');
        self::assertStringContainsString('事务观测失败', (string) file_get_contents($this->obsLog));
    }

    public function test_无人监听时事务照常工作(): void
    {
        $this->conn->beginTransaction();
        $this->conn->beginTransaction();
        $this->conn->insert('INSERT INTO tx (name) VALUES (?)', ['quiet']);
        $this->conn->rollBack();
        $this->conn->commit();

        self::assertSame([], $this->seen);
        self::assertSame([], $this->conn->select('SELECT name FROM tx'));
    }

    // ===================== ORM 桥接器同口径 =====================

    public function test_Laravel桥接器事务入口同样派发事件(): void
    {
        $this->record();
        $fake = new FakeTxOrmConnection();
        $bridge = new LaravelTxProbe($fake, ['connection_name' => 'laravel_tx']);

        $bridge->beginTransaction();
        $bridge->rollBack();

        self::assertSame(['begin', 'rollback'], $fake->calls, '底层 ORM 门面被如实委托');
        self::assertSame([
            [TransactionBeginEvent::class, 1],
            [TransactionRollbackEvent::class, 1],
        ], $this->signatures());
        self::assertSame('laravel_tx', $this->seen[0]->getConnection());

        // 事务已关闭：后续裸回滚属于未跟踪调用，不产生配对噪音
        $bridge->rollBack();
        self::assertCount(2, $this->seen);
    }

    public function test_ThinkPHP桥接器事务入口同样派发事件(): void
    {
        $this->record();
        $fake = new FakeTxOrmConnection();
        $bridge = new ThinkPHTxProbe($fake, ['connection_name' => 'tp_tx']);

        $bridge->beginTransaction();
        $bridge->rollBack();

        self::assertSame(['startTrans', 'rollback'], $fake->calls);
        self::assertSame([
            [TransactionBeginEvent::class, 1],
            [TransactionRollbackEvent::class, 1],
        ], $this->signatures());
    }

    public function test_桥接器嵌套层级由观测切面维护(): void
    {
        $this->record();
        $bridge = new LaravelTxProbe(new FakeTxOrmConnection());

        $bridge->beginTransaction();
        $bridge->beginTransaction();
        $bridge->commit();
        $bridge->commit();

        self::assertSame([
            [TransactionBeginEvent::class, 1],
            [TransactionBeginEvent::class, 2],
            [TransactionCommitEvent::class, 2],
            [TransactionCommitEvent::class, 1],
        ], $this->signatures());
    }

    // ===================== 辅助 =====================

    private function record(): void
    {
        $em = EventManager::getInstance();
        foreach ([
            TransactionBeginEvent::class,
            TransactionCommitEvent::class,
            TransactionRollbackEvent::class,
        ] as $event) {
            $em->listen($event, function (object $e): void {
                $this->seen[] = $e;
            });
        }
    }

    /**
     * @return list<array{0: class-string, 1: int}>
     */
    private function signatures(): array
    {
        return array_map(
            static fn (object $e): array => [$e::class, $e->getLevel()],
            $this->seen,
        );
    }
}

/**
 * 桥接器底下的假 ORM 连接：把事务入口的两种拼写（Laravel/Hyperf 与 ThinkPHP）都摆齐。
 */
final class FakeTxOrmConnection
{
    /** @var list<string> */
    public array $calls = [];

    public function beginTransaction(): void
    {
        $this->calls[] = 'begin';
    }

    public function commit(): void
    {
        $this->calls[] = 'commit';
    }

    public function startTrans(): void
    {
        $this->calls[] = 'startTrans';
    }

    // PHP 方法名大小写不敏感：ThinkPHP 的 ->rollback() 与 Laravel 的 ->rollBack() 落到同一方法
    public function rollBack(): void
    {
        $this->calls[] = 'rollback';
    }
}

final class LaravelTxProbe extends LaravelBridge
{
    public function __construct(public readonly FakeTxOrmConnection $fake, array $config = [])
    {
        parent::__construct($config);
    }

    #[\Override]
    protected function db(): object
    {
        return $this->fake;
    }
}

final class ThinkPHTxProbe extends ThinkPHPBridge
{
    public function __construct(public readonly FakeTxOrmConnection $fake, array $config = [])
    {
        parent::__construct($config);
    }

    #[\Override]
    protected function conn(): object
    {
        return $this->fake;
    }
}
