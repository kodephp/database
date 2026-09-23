<?php

declare(strict_types=1);

namespace Kode\Database\Tests;

use Kode\Database\Db\Db;
use Kode\Database\Database\Migrations\Migrator;
use Kode\Database\Pool\PoolManager;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/**
 * Migrator 的预演（pretend）与连接归属。
 *
 * 两处「看起来对、实际错」的形状：
 *  1. `kode migrate --pretend` 之类的不存在选项被 console 静默忽略，
 *     于是操作者以为在看回放、实际把迁移落进了库。预演必须是真执行 + 整体回滚：
 *     只有跑一遍才看得出「字段已存在 / 约束冲突」这类只有执行才暴露的问题。
 *  2. Migrator 收了 $connection，但 `Db::` 门面（statement/select/insert/事务）一律走默认连接，
 *     而迁移文件内部写的也是 `Db::` —— 只把记账语句带上连接名，就会记账在 A 库、建表在 B 库。
 *
 * 用 SQLite 内存库跑：DDL 可在事务内回滚，且不碰任何真实服务器。
 */
final class MigratorPretendTest extends TestCase
{
    private const CONN = 'mig_probe';

    private string $dir = '';

    /** @var array<string, mixed> 进入本文件前的静态状态 */
    private array $snapshot = [];

    private const DB_PROPS = [
        'config', 'connections', 'connectionCache', 'transactionConnections', 'transactionDepth',
        'defaultConnection',
    ];

    private const POOL_PROPS = ['pools', 'poolTypes', 'contextPools', 'driver', 'poolType'];

    protected function setUp(): void
    {
        parent::setUp();

        foreach (self::DB_PROPS as $prop) {
            $this->snapshot[Db::class . '::' . $prop] = $this->staticProp(Db::class, $prop);
        }
        foreach (self::POOL_PROPS as $prop) {
            $this->snapshot[PoolManager::class . '::' . $prop] = $this->staticProp(PoolManager::class, $prop);
        }

        $this->dir = sys_get_temp_dir() . '/kode_mig_' . uniqid('', true);
        mkdir($this->dir, 0700, true);

        Db::addConnection(self::CONN, ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        Db::setDefaultConnection(self::CONN);
    }

    protected function tearDown(): void
    {
        Db::removeConnection(self::CONN);
        foreach (glob($this->dir . '/*.php') ?: [] as $file) {
            unlink($file);
        }
        @rmdir($this->dir);

        foreach ($this->snapshot as $key => $value) {
            [$class, $prop] = explode('::', $key, 2);
            $this->setStaticProp($class, $prop, $value);
        }

        parent::tearDown();
    }

    public function test_pretend_rolls_everything_back(): void
    {
        $this->putMigration('create_widgets', <<<'PHP'
    public function up(): void
    {
        \Kode\Database\Db\Db::statement('CREATE TABLE widgets (id INTEGER PRIMARY KEY, name TEXT)');
    }
    PHP);
        $this->putMigration('seed_widgets', <<<'PHP'
    public function up(): void
    {
        \Kode\Database\Db\Db::statement('INSERT INTO widgets (name) VALUES (\'tick\')');
    }
    PHP);

        $migrator = new Migrator($this->dir, self::CONN);
        $names = $migrator->pretend();

        // 预演必须真的执行到第二条：否则「跑通了」三个字毫无意义
        self::assertSame(['0000_00_00_000000_create_widgets', '0000_00_00_000000_seed_widgets'], $names);
        // 回滚要连 DDL 一起撤（SQLite/PostgreSQL 的事务型 DDL 才做得到）
        self::assertFalse(Db::tableExists('widgets'), '预演把表留下了');
        self::assertSame([], $migrator->getRan(), '预演把迁移记进了账');
    }

    public function test_pretend_leaves_the_batch_number_untouched(): void
    {
        $this->putMigration('a_one', $this->createTable());

        $migrator = new Migrator($this->dir, self::CONN);
        self::assertSame(1, $this->nextBatch($migrator), '预演推进了批次号');

        $migrator->pretend();
        self::assertSame(1, $this->nextBatch($migrator), '预演推进了批次号');

        $migrator->run();
        self::assertSame(2, $this->nextBatch($migrator), '真执行后批次号该前进');
        self::assertTrue(Db::tableExists('a_one'));
    }

    public function test_pretend_reports_failure_instead_of_passing(): void
    {
        $this->putMigration('a_good', $this->createTable());
        $this->putMigration('b_bad', <<<'PHP'
    public function up(): void
    {
        \Kode\Database\Db\Db::statement('CREATE TABLE nope (id INTEGER PRIMARY KEY)');
        throw new \RuntimeException('迁移内部失败');
    }
    PHP);

        $migrator = new Migrator($this->dir, self::CONN);
        try {
            $migrator->pretend();
            self::fail('失败的迁移被放行了');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('迁移内部失败', $e->getMessage());
        }
        // 失败前已执行的语句也必须整体撤掉（同一个事务）
        self::assertFalse(Db::tableExists('a_good'));
        self::assertFalse(Db::tableExists('nope'));
    }

    public function test_pretend_then_run_in_one_process_resolves_the_class_again(): void
    {
        $this->putMigration('twice_checked', $this->createTable());
        $migrator = new Migrator($this->dir, self::CONN);

        // require_once 第二次不再产出新类，只按 get_declared_classes() 差集找会误报「没有 Migration 子类」
        self::assertSame(['0000_00_00_000000_twice_checked'], $migrator->pretend());
        self::assertSame(['0000_00_00_000000_twice_checked'], $migrator->run());
        self::assertTrue(Db::tableExists('twice_checked'));
        self::assertSame([], $migrator->pretend(), '已记录的迁移不该再被预演');
    }

    public function test_migrations_run_on_the_connection_they_were_given(): void
    {
        // 另开一条库，迁移文件里用的是 Db:: 门面（默认连接）—— 建出来的表必须落在那条库上
        Db::addConnection('mig_other', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        try {
            $this->putMigration('on_other', $this->createTable());
            $migrator = new Migrator($this->dir, 'mig_other');
            self::assertSame(['0000_00_00_000000_on_other'], $migrator->run());

            Db::setDefaultConnection('mig_other');
            self::assertTrue(Db::tableExists('on_other'), '迁移建到了别的库');
            self::assertArrayHasKey('0000_00_00_000000_on_other', $migrator->getRan(), '迁移记账建到了别的库');

            Db::setDefaultConnection(self::CONN);
            self::assertFalse(Db::tableExists('on_other'), '默认连接上多了张表');
            self::assertFalse(Db::tableExists('migrations'), '默认连接上多了张记账表');
        } finally {
            Db::removeConnection('mig_other');
        }
    }

    public function test_the_default_connection_is_restored_afterwards(): void
    {
        // 常驻进程里漏一次还原，之后所有走默认连接的查询都会跑到迁移库上
        Db::addConnection('mig_restore', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        $this->putMigration('restore_check', $this->createTable());

        try {
            (new Migrator($this->dir, 'mig_restore'))->pretend();
            self::assertSame(self::CONN, Db::getDefaultConnection(), '预演把默认连接换走了没还回来');

            (new Migrator($this->dir, 'mig_restore'))->run();
            self::assertSame(self::CONN, Db::getDefaultConnection(), '执行把默认连接换走了没还回来');

            Db::setDefaultConnection('mig_restore');
            self::assertTrue(Db::tableExists('restore_check'));
        } finally {
            Db::removeConnection('mig_restore');
        }
    }

    /**
     * 一个文件里声明了两个迁移类时，文件名对得上的那个才算数
     *
     * 靠「本次 require 新出现的类」找迁移，遇到一个文件带出多个 Migration 子类就会挑错对象
     * （先声明的那个），执行的根本不是这个文件声称要执行的东西。
     */
    public function test_the_class_named_after_the_file_wins_when_a_file_declares_two(): void
    {
        $file = sprintf('%s/0000_00_00_000000_chosen.php', $this->dir);
        file_put_contents($file, <<<'PHP'
<?php

declare(strict_types=1);

use Kode\Database\Database\Migrations\Migration;

final class DeceptiveFirst extends Migration
{
    public function up(): void
    {
        \Kode\Database\Db\Db::statement('CREATE TABLE deceptive (id INTEGER PRIMARY KEY)');
    }

    public function down(): void
    {
    }
}

final class Chosen extends Migration
{
    public function up(): void
    {
        \Kode\Database\Db\Db::statement('CREATE TABLE chosen (id INTEGER PRIMARY KEY)');
    }

    public function down(): void
    {
    }
}

PHP);

        self::assertSame(['0000_00_00_000000_chosen'], (new Migrator($this->dir, self::CONN))->run());
        self::assertTrue(Db::tableExists('chosen'), '执行的不是文件名对应的那个迁移类');
        self::assertFalse(Db::tableExists('deceptive'), '挑错了迁移类，多建了一张表');
    }

    /**
     * 文件名推出的类必须先确认是迁移子类才能实例化
     *
     * 回退查按名字猜，猜中的可能只是个同名普通类；直接 new 出来调 up() 会当场 fatal，
     * 报错也说不清是哪个文件的问题。
     */
    public function test_a_non_migration_class_named_after_the_file_is_not_instantiated(): void
    {
        file_put_contents(sprintf('%s/0000_00_00_000000_impostor.php', $this->dir), <<<'PHP'
<?php

declare(strict_types=1);

final class Impostor
{
    public function up(): void
    {
    }
}

PHP);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('未在迁移文件中找到 Migration 子类');

        (new Migrator($this->dir, self::CONN))->run();
    }

    // ---- helpers ----------------------------------------------------------

    /** protected：测试要读批次号，但不值得为此抬成公共 API */
    private function nextBatch(Migrator $migrator): int
    {
        $ref = new \ReflectionMethod($migrator, 'nextBatch');
        $ref->setAccessible(true);

        return (int) $ref->invoke($migrator);
    }

    /** @return string 建表 + 插一行的 up() 片段 */
    private function createTable(): string
    {
        return <<<'PHP'
    public function up(): void
    {
        \Kode\Database\Db\Db::statement('CREATE TABLE placeholder (id INTEGER PRIMARY KEY)');
    }
    PHP;
    }

    /**
     * 写一个迁移文件：文件名 -> 类名按约定大写驼峰，表名用文件名，互不冲突。
     */
    private function putMigration(string $snake, string $body): void
    {
        $class = str_replace(' ', '', ucwords(str_replace('_', ' ', $snake)));
        $sqlTable = $snake;
        $body = str_replace('placeholder', $sqlTable, $body);
        $file = sprintf('%s/0000_00_00_000000_%s.php', $this->dir, $snake);

        file_put_contents($file, <<<PHP
<?php

declare(strict_types=1);

use Kode\Database\Database\Migrations\Migration;

final class {$class} extends Migration
{
{$body}
    public function down(): void
    {
    }
}

PHP);
    }

    private function staticProp(string $class, string $name): mixed
    {
        $ref = new ReflectionProperty($class, $name);
        $ref->setAccessible(true);

        return $ref->getValue();
    }

    private function setStaticProp(string $class, string $name, mixed $value): void
    {
        $ref = new ReflectionProperty($class, $name);
        $ref->setAccessible(true);
        $ref->setValue(null, $value);
    }
}
