<?php

declare(strict_types=1);

namespace Kode\Database\Tests;

use Kode\Database\Db\Db;
use Kode\Database\Pool\PoolManager;
use PHPUnit\Framework\TestCase;

/**
 * Db::removeConnection() / PoolManager::remove() —— 命名连接的回收。
 *
 * 场景：常驻多进程运行时里，业务在请求期临时注册连接（建库用的维护连接、
 * 迁移期的一批分片库）。此前只有 addConnection，没有成对的反向操作：
 * 名字、池对象和池里已建立的 PDO 会一直留在 worker 上，
 * clear()/disconnect() 又是「全清」，会把别的在途连接一起干掉。
 */
final class RemoveConnectionTest extends TestCase
{
    /** @var array<string, mixed> 进入本文件前的静态状态，tearDown 原样放回 */
    private array $snapshot = [];

    /** 本文件会动的静态位（常驻进程语义下这些全是全局的，漏一个就会污染同进程跑的别的用例） */
    private const DB_PROPS = [
        'config', 'connections', 'connectionCache', 'transactionConnections', 'transactionDepth',
        'defaultConnection', 'queryLogs', 'queryLogEnabled', 'lastSql',
    ];

    private const POOL_PROPS = ['pools', 'poolTypes', 'contextPools', 'driver', 'poolType'];

    protected function setUp(): void
    {
        foreach (self::DB_PROPS as $prop) {
            $this->snapshot[Db::class . '::' . $prop] = $this->staticProp(Db::class, $prop);
        }
        foreach (self::POOL_PROPS as $prop) {
            $this->snapshot[PoolManager::class . '::' . $prop] = $this->staticProp(PoolManager::class, $prop);
        }
    }

    protected function tearDown(): void
    {
        // 快照放回 = 本文件产生的连接名/池随之丢弃（PDO 对象没人引用后由 PHP 关掉），
        // 同时把查询日志、默认 driver 指针这些全局位还原，别污染同进程跑的别的用例。
        foreach ($this->snapshot as $key => $value) {
            [$class, $prop] = explode('::', $key, 2);
            $this->setStaticProp($class, $prop, $value);
        }
    }

    private function staticProp(string $class, string $name): mixed
    {
        $ref = new \ReflectionProperty($class, $name);
        $ref->setAccessible(true);

        return $ref->getValue();
    }

    private function setStaticProp(string $class, string $name, mixed $value): void
    {
        $ref = new \ReflectionProperty($class, $name);
        $ref->setAccessible(true);
        $ref->setValue(null, $value);
    }

    /** 内存 sqlite：不需要任何外部服务就能真实建连。 */
    private function addTempConnection(string $name): void
    {
        Db::addConnection($name, [
            'driver' => 'sqlite',
            'host' => '',
            'port' => 0,
            'database' => ':memory:',
            'username' => '',
            'password' => '',
            'charset' => 'utf8',
            'prefix' => '',
        ]);
    }

    public function testRemovingARegisteredConnectionReleasesNameAndPool(): void
    {
        $this->addTempConnection('zz_tmp_release');
        self::assertTrue(Db::hasConnection('zz_tmp_release'));
        self::assertTrue(PoolManager::hasPool('zz_tmp_release'), 'addConnection 应当已经把池建好');

        // 真的建立一条底层连接，移除时才有东西可释放（否则这条用例只是测数组下标）
        $conn = Db::getConnection('zz_tmp_release');
        self::assertNotNull($conn);
        $pool = PoolManager::getPool('zz_tmp_release');
        self::assertSame(1, $pool->getStats()['available'], '取用后池里应挂着一条活连接');

        self::assertTrue(Db::removeConnection('zz_tmp_release'));

        self::assertFalse(Db::hasConnection('zz_tmp_release'), '配置里的名字必须摘掉');
        self::assertFalse(PoolManager::hasPool('zz_tmp_release'), '池必须一并关闭并摘掉');
        // 拿着旧引用看内部状态：close() 必须真的断掉底层连接，而不是只把名字删了
        self::assertSame(0, $pool->getStats()['available'], '底层连接未被断开，等于连接泄漏');
    }

    public function testRemovingTheSameNameTwiceIsFalseTheSecondTime(): void
    {
        $this->addTempConnection('zz_tmp_twice');

        self::assertTrue(Db::removeConnection('zz_tmp_twice'));
        self::assertFalse(Db::removeConnection('zz_tmp_twice'), '重复移除不该谎报成功');
    }

    public function testUnknownNameRemovesNothing(): void
    {
        // 未知名字会被各处回落成全局配置，所以「没注册过」必须回 false，
        // 而且不能顺手把默认连接的池关掉。
        Db::setConfig(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);

        self::assertFalse(Db::removeConnection('zz_never_registered'));
        self::assertTrue(PoolManager::hasPool('sqlite'), '默认配置的池不该被牵连');
    }

    public function testDefaultConnectionCannotBeRemoved(): void
    {
        Db::setConfig(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        $this->addTempConnection('default');

        self::assertFalse(Db::removeConnection('default'), '默认连接移除属于误用，必须拒绝');
        self::assertTrue(Db::hasConnection('default'), '被拒绝后配置要原样留着');
    }

    public function testRemovingANameDoesNotDisturbOtherConnections(): void
    {
        Db::setConfig(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        $this->addTempConnection('zz_tmp_keep');
        $this->addTempConnection('zz_tmp_drop');
        $keep = Db::getConnection('zz_tmp_keep');

        self::assertTrue(Db::removeConnection('zz_tmp_drop'));

        self::assertNotNull(PoolManager::getPool('zz_tmp_keep')->getStats());
        self::assertSame($keep, Db::getConnection('zz_tmp_keep'), '在用的另一条连接不能被换掉或断开');
    }

    public function testPoolRemoveReportsWhetherAPoolWasDropped(): void
    {
        $this->addTempConnection('zz_tmp_pool_only');

        self::assertTrue(PoolManager::remove('zz_tmp_pool_only'));
        self::assertFalse(PoolManager::remove('zz_tmp_pool_only'));
        self::assertFalse(PoolManager::remove(''), '空名字直接 false，不去碰默认驱动');
    }
}
