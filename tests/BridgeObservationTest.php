<?php

declare(strict_types=1);

namespace Kode\Database\Tests;

use Kode\Database\Connection\Bridge\HyperfBridge;
use Kode\Database\Connection\Bridge\LaravelBridge;
use Kode\Database\Connection\Bridge\SymfonyBridge;
use Kode\Database\Connection\Bridge\ThinkPHPBridge;
use Kode\Database\Db\Connection as DbConnection;
use Kode\Database\Db\Db;
use Kode\Database\Event\EventManager;
use Kode\Database\Event\SqlEvent;
use Kode\Database\Tests\Support\RecordingListener;
use PHPUnit\Framework\TestCase;
use Throwable;

/**
 * ORM 桥接器的查询观测测试。
 *
 * 观测切面（{@see \Kode\Database\Connection\QueryObservation}）如果只挂在内置 PdoConnection 上，
 * 开发者一旦配 driver=laravel/symfony/... 就会静默失去日志、钩子与事件 —— 又是一处「文档说有、实际没有」。
 * 这里用假连接替掉桥接器底下的 ORM 门面，验证四个桥接器都走同一个切面（不需要真的装 ORM）。
 */
final class BridgeObservationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        EventManager::resetInstance();
        DbConnection::clearQueryHooks();
        Db::enableQueryLog(false);
        RecordingListener::$events = [];

        Db::enableQueryLog(true);
        EventManager::getInstance()->listen(SqlEvent::class, RecordingListener::class);
    }

    protected function tearDown(): void
    {
        EventManager::resetInstance();
        DbConnection::clearQueryHooks();
        Db::enableQueryLog(false);

        parent::tearDown();
    }

    public function test_Laravel桥接器的查询同样进观测(): void
    {
        $bridge = new LaravelProbe(new FakeOrmConnection(), ['connection_name' => 'laravel_test']);
        $this->expectObservation($bridge->select('SELECT * FROM users'), 'SELECT * FROM users', 'laravel_test');
    }

    public function test_Hyperf桥接器的查询同样进观测(): void
    {
        $bridge = new HyperfProbe(new FakeOrmConnection(), ['connection_name' => 'hyperf_test']);
        $this->expectObservation($bridge->update('UPDATE users SET name = ?', ['ada']), 'UPDATE users SET name = ?', 'hyperf_test');
    }

    public function test_ThinkPHP桥接器的查询同样进观测(): void
    {
        $bridge = new ThinkPHPProbe(new FakeOrmConnection(), ['connection_name' => 'tp_test']);
        $this->expectObservation($bridge->statement('TRUNCATE users'), 'TRUNCATE users', 'tp_test');
    }

    public function test_桥接器执行失败时异常照旧上报观测(): void
    {
        // doctrine/dbal 没装时 conn() 抛 ConnectionException：报错本身仍是值得记录的一次查询。
        if (class_exists('Doctrine\DBAL\DriverManager')) {
            $this->markTestSkipped('已安装 doctrine/dbal，本用例会去连真实数据库');
        }

        $seen = null;
        DbConnection::afterQuery(static function (string $sql, array $bindings, mixed $result) use (&$seen): void {
            $seen = $result;
        });

        $bridge = new SymfonyBridge(['connection_name' => 'symfony_test']);

        try {
            $bridge->select('SELECT 1');
            self::fail('桥接器应当把连接异常抛出');
        } catch (Throwable $e) {
            self::assertInstanceOf(Throwable::class, $e);
        }

        self::assertSame($e, $seen, 'after 钩子必须收到同一个异常');
        self::assertSame('SELECT 1', Db::getLastSql());
    }

    private function expectObservation(mixed $result, string $sql, string $connection): void
    {
        self::assertNotNull($result);

        $log = Db::getQueryLog();
        self::assertCount(1, $log);
        self::assertSame($sql, $log[0]['sql']);
        // 连接名来自配置：读写分离/分库下没有名字就没法把慢 SQL 归到具体链路。
        self::assertSame($connection, $log[0]['connection']);
        self::assertGreaterThan(0.0, $log[0]['time']);
        self::assertCount(1, RecordingListener::$events);
        self::assertSame($sql, RecordingListener::$events[0]->getSql());
    }
}

/**
 * 桥接器底下的假 ORM 连接：把 Laravel / Hyperf / ThinkPHP 三套门面用到的方法都摆齐。
 */
final class FakeOrmConnection
{
    public string $lastSql = '';

    /** @var array<mixed> */
    public array $lastBindings = [];

    public function select(string $sql, array $bindings = []): array
    {
        return $this->call($sql, $bindings);
    }

    public function query(string $sql, array $bindings = []): array
    {
        return $this->call($sql, $bindings);
    }

    public function insert(string $sql, array $bindings = []): bool
    {
        $this->call($sql, $bindings);

        return true;
    }

    public function update(string $sql, array $bindings = []): int
    {
        $this->call($sql, $bindings);

        return 1;
    }

    public function delete(string $sql, array $bindings = []): int
    {
        $this->call($sql, $bindings);

        return 1;
    }

    public function execute(string $sql, array $bindings = []): int
    {
        $this->call($sql, $bindings);

        return 1;
    }

    public function statement(string $sql, array $bindings = []): bool
    {
        $this->call($sql, $bindings);

        return true;
    }

    public function getLastInsID(): int
    {
        return 7;
    }

    public function getPdo(): self
    {
        return $this;
    }

    public function lastInsertId(): string
    {
        return '7';
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function call(string $sql, array $bindings): array
    {
        $this->lastSql = $sql;
        $this->lastBindings = $bindings;

        return [['id' => 1, 'name' => 'seed']];
    }
}

final class LaravelProbe extends LaravelBridge
{
    #[\Override]
    protected function db(): object
    {
        return $this->fake;
    }

    public function __construct(public readonly FakeOrmConnection $fake, array $config = [])
    {
        parent::__construct($config);
    }
}

final class HyperfProbe extends HyperfBridge
{
    #[\Override]
    protected function db(): object
    {
        return $this->fake;
    }

    public function __construct(public readonly FakeOrmConnection $fake, array $config = [])
    {
        parent::__construct($config);
    }
}

final class ThinkPHPProbe extends ThinkPHPBridge
{
    #[\Override]
    protected function conn(): object
    {
        return $this->fake;
    }

    public function __construct(public readonly FakeOrmConnection $fake, array $config = [])
    {
        parent::__construct($config);
    }
}
