<?php

declare(strict_types=1);

namespace Kode\Database\Tests;

use Kode\Database\Db\Db;
use Kode\Database\Model\Model;
use Kode\Database\Model\Relation\BelongsTo;
use Kode\Database\Model\Relation\BelongsToMany;
use Kode\Database\Model\Relation\HasMany;
use Kode\Database\Model\Relation\HasOne;
use Kode\Database\Pool\PoolManager;
use PHPUnit\Framework\TestCase;

/**
 * ORM 接线回归：模型-连接-关系-DSL 全链路。
 *
 * 背景：这批缺陷全部在「单测从未覆盖」的缝里存活 —— Model 缺 Db 导入（save/find 直接 fatal）、
 * 关系工厂拿空原型当 parent（所有关系绑定 user_id = null）、预加载塞裸数组（不是模型实例）、
 * with/withCount/has 文档 API 与 PHP 静态语义冲突（调用即 Error）、select() 变参收数组嵌套成 SELECT Array。
 * 每条断言对应一个曾经真实发生的故障形态，不是形状测试。
 *
 * 静态位（连接名/池/默认连接指针）按 RemoveConnectionTest 的惯例快照还原，避免污染同进程其余用例。
 */
final class OrmWiringTest extends TestCase
{
    /** @var array<string, mixed> 进入本文件前的静态状态，tearDown 原样放回 */
    private array $snapshot = [];

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

        Db::addConnection('orm_wiring', ['driver' => 'sqlite', 'database' => ':memory:']);
        Db::setDefaultConnection('orm_wiring');

        Db::statement('CREATE TABLE orm_users (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT, role TEXT, deleted_at TEXT)');
        Db::statement('CREATE TABLE orm_posts (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER, title TEXT)');
        Db::statement('CREATE TABLE orm_profiles (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER, bio TEXT)');
        Db::statement('CREATE TABLE orm_tags (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT)');
        Db::statement('CREATE TABLE orm_user_tag (user_id INTEGER, tag_id INTEGER)');

        Db::table('orm_users')->insertAll([
            ['name' => 'ada', 'role' => 'admin'],
            ['name' => 'bob', 'role' => 'member'],
            ['name' => 'carol', 'role' => 'member'],
        ]);
        Db::table('orm_posts')->insertAll([
            ['user_id' => 1, 'title' => 'p1'],
            ['user_id' => 1, 'title' => 'p2'],
        ]);
        Db::table('orm_profiles')->insert(['user_id' => 1, 'bio' => 'bio-of-ada']);
        Db::table('orm_tags')->insertAll([['name' => 'php'], ['name' => 'test']]);
        Db::table('orm_user_tag')->insertAll([['user_id' => 1, 'tag_id' => 1], ['user_id' => 1, 'tag_id' => 2]]);
    }

    protected function tearDown(): void
    {
        foreach ($this->snapshot as $key => $value) {
            [$class, $prop] = explode('::', $key, 2);
            $this->setStaticProp($class, $prop, $value);
        }
    }

    /* ==================== 模型接线：save/find/水合/魔术属性 ==================== */

    public function testSaveInsertsThenUpdatesAndFindsHydratedModel(): void
    {
        $user = new OrmWUser(['name' => 'dave', 'role' => 'member']);
        $this->assertTrue($user->save(), '新模型 save 走 INSERT');
        $this->assertTrue($user->exists);
        $this->assertNotNull($user->id);

        $user->role = 'admin';
        $this->assertTrue($user->save(), 'exists 模型 save 走 UPDATE');
        $this->assertSame(1, Db::table('orm_users')->where('id', $user->id)->count());

        $found = OrmWUser::find($user->id);
        $this->assertInstanceOf(OrmWUser::class, $found, 'find 返回模型而不是裸数组');
        $this->assertSame('admin', $found->getAttribute('role'));
        $this->assertFalse($found->isDirty(), '水合后与库内一致 = 干净');

        $this->assertSame(4, count(OrmWUser::all()), 'all() 全部水合');
        $this->assertSame(4, count((new OrmWUser())->get()), '实例 get() 全部水合（静态形态走 with()->get() 链）');
        $this->assertInstanceOf(OrmWUser::class, OrmWUser::first());
    }

    public function testMagicAttributeAccessors(): void
    {
        $user = OrmWUser::find(1);
        $this->assertSame('ada', $user->name, '__get 走 getAttribute');

        $user->name = 'ada2';
        $this->assertSame('ada2', $user->getAttribute('name'), '__set 走 setAttribute');
        $this->assertTrue(isset($user->name), '__isset 委托属性存在性');
        $this->assertTrue($user->isDirty('name'));

        unset($user->name);
        $this->assertFalse(isset($user->name), '__unset 移除属性');
    }

    public function testCreateOrUpdateHydratesAndReturnsModel(): void
    {
        $one = OrmWUser::one(['name' => 'ada']);
        $this->assertInstanceOf(OrmWUser::class, $one, 'one() 返回水合模型（曾返回裸数组 → TypeError）');

        $same = OrmWUser::firstOrCreate(['name' => 'ada']);
        $this->assertInstanceOf(OrmWUser::class, $same);
        $this->assertSame(3, count(OrmWUser::all()), 'firstOrCreate 已存在时不插行');

        $fresh = OrmWUser::firstOrCreate(['name' => 'erin'], ['role' => 'admin']);
        $this->assertInstanceOf(OrmWUser::class, $fresh);
        $this->assertSame('admin', $fresh->getAttribute('role'));
        $this->assertSame(4, count(OrmWUser::all()));

        $updated = OrmWUser::updateOrCreate(['name' => 'frank'], ['role' => 'owner']);
        $this->assertInstanceOf(OrmWUser::class, $updated, 'updateOrCreate 未命中时插入并水合返回');
        $this->assertSame('owner', $updated->getAttribute('role'));

        $target = OrmWUser::updateOrCreate(['name' => 'frank'], ['role' => 'vip']);
        $this->assertSame('vip', $target->getAttribute('role'));
        $this->assertSame('vip', Db::table('orm_users')->where('name', 'frank')->value('role'));
        $this->assertSame(5, count(OrmWUser::all()), '命中时走 UPDATE 不加行');
    }

    public function testChangesAndSummaryReflectDirtyState(): void
    {
        $user = OrmWUser::find(1);
        $this->assertSame([], $user->getChanges(), '水合模型无变更');
        $this->assertTrue($user->isClean());

        $user->role = 'root';
        $changes = $user->getChanges();
        $this->assertArrayHasKey('role', $changes, 'getChanges 必须与 isDirty($key) 同口径（曾恒空）');
        $this->assertSame('root', $changes['role']);
        $this->assertFalse($user->isClean());
        $this->assertSame(1, $user->summary()['changes'] ?? count($user->getChanges()));
    }

    public function testIsDeletedRequiresSoftDeleteFieldAndValue(): void
    {
        $clean = OrmWUser::find(1);
        $this->assertFalse($clean->isDeleted(), '未设置软删字段值 → 未删除');

        Db::table('orm_users')->where('id', 2)->update(['deleted_at' => '2026-01-01 00:00:00']);
        $deleted = OrmWUser::find(2);
        $this->assertTrue($deleted->isDeleted(), '软删字段有值 → 已删除（曾恒 false）');

        $hard = new OrmWPost(['user_id' => 9, 'title' => 'x']);
        $this->assertFalse($hard->isDeleted(), '未启用软删的模型恒 false');
    }

    public function testHasDefaultAndDefaultsNotTreatedAsAttributes(): void
    {
        $user = new OrmWUser(['name' => 'zoe']);
        $this->assertTrue($user->hasDefault('role'), 'defaults 提供的默认值可读');
        $this->assertSame('member', $user->getDefault('role'));
        $this->assertSame('member', $user->toArrayWithDefaults()['role'], '序列化并入默认值（README 契约）');
        $this->assertFalse($user->isDirty('role'), 'defaults 不进入 attributes → 不算用户变更');
    }

    /* ==================== DSL：静态入口 + 实例续链（双层 magic） ==================== */

    public function testStaticWithEagerLoadsHydratedRelationModels(): void
    {
        $users = OrmWUser::with('posts')->get();
        $this->assertCount(3, $users, '种子三行');
        $this->assertInstanceOf(OrmWUser::class, $users[0]);

        $posts = $users[0]->getRelation('posts');
        $this->assertIsArray($posts);
        $this->assertCount(2, $posts, 'ada 有两篇');
        $this->assertInstanceOf(OrmWPost::class, $posts[0], '预加载必须是模型实例（曾塞裸数组）');
        $this->assertSame('p1', $posts[0]->title);

        $this->assertSame([], $users[1]->getRelation('posts'), '无关联者空数组');
    }

    public function testWithCountAndMapFormCallback(): void
    {
        $users = OrmWUser::withCount('posts')->get();
        $this->assertSame(2, $users[0]->getAttribute('posts_count'));
        $this->assertSame(0, $users[1]->getAttribute('posts_count'));

        $filtered = OrmWUser::withCount(['posts' => static fn ($q) => $q->where('title', 'p1')])->get();
        $this->assertSame(1, $filtered[0]->getAttribute('posts_count'), 'map 形态回调被应用');
    }

    public function testHasOverloadDualSemantics(): void
    {
        $withPosts = OrmWUser::has('posts', '>=', 1)->get();
        $this->assertCount(1, $withPosts);
        $this->assertSame('ada', $withPosts[0]->name);

        $this->assertCount(0, OrmWUser::has('posts', '>=', 99)->get());

        $exists = OrmWUser::has(['name' => 'ada']);
        $this->assertTrue($exists, '数组形态 = 记录存在性，返回 bool');
        $this->assertFalse(OrmWUser::has(['name' => 'nobody']));
    }

    public function testWhereHasOrWhereHasDoesNotHaveAndChains(): void
    {
        $hit = OrmWUser::whereHas('posts', static fn ($q) => $q->where('title', 'p1'))->get();
        $this->assertCount(1, $hit);
        $this->assertSame('ada', $hit[0]->name);

        $miss = OrmWUser::whereHas('posts', static fn ($q) => $q->where('title', 'nope'))->get();
        $this->assertCount(0, $miss);

        $noPosts = OrmWUser::doesNotHave('posts')->get();
        $this->assertCount(2, $noPosts, 'bob/carol 无文章');

        // 静态入口 + 实例续链混搭（双层 magic 的核心形态）
        $chained = OrmWUser::with('posts')
            ->withCount('posts')
            ->has('posts', '>=', 1)
            ->get();
        $this->assertCount(1, $chained);
        $this->assertSame(2, $chained[0]->getAttribute('posts_count'));
        $this->assertCount(2, $chained[0]->getRelation('posts'));

        $orChain = OrmWUser::whereHas('posts', static fn ($q) => $q->where('title', 'nope'))
            ->orWhereHas('posts', static fn ($q) => $q->where('title', 'p2'))
            ->get();
        $this->assertCount(1, $orChain, 'orWhereHas 的 OR 分支成立');
    }

    public function testWithWhereHasAppliesCallbackWithoutTypeError(): void
    {
        // 曾存裸 Closure，get() 处 applyHasConstraint(array) 直接 TypeError
        $users = OrmWUser::withWhereHas('posts', static fn ($q) => $q->where('title', 'p1'))->get();
        $this->assertCount(1, $users);
        $this->assertSame('ada', $users[0]->name);

        $list = OrmWUser::withWhereHas(['posts'])->get();
        $this->assertCount(3, $list, '无回调的列表形态只预加载不筛行');
    }

    public function testUndefinedMagicMethodThrowsBadMethodCall(): void
    {
        $this->expectException(\BadMethodCallException::class);
        OrmWUser::totallyUnknownDsl();
    }

    /* ==================== 关系：parent 绑定 / 水合 / 加载器 ==================== */

    public function testDirectRelationQueriesBindParentPrimaryKey(): void
    {
        // 曾：关系工厂用空原型当 parent → WHERE user_id = null → 永远空
        $user = OrmWUser::find(1);
        $posts = $user->posts()->getResults();
        $this->assertIsArray($posts);
        $this->assertCount(2, $posts);
        $this->assertInstanceOf(OrmWPost::class, $posts[0]);

        $first = $user->posts()->first();
        $this->assertInstanceOf(OrmWPost::class, $first, 'Relation::first 多关系返回首个模型而不是裸数组');
        $this->assertSame('p1', $first->title);

        $profile = $user->profile()->getResults();
        $this->assertInstanceOf(OrmWProfile::class, $profile, 'HasOne 返回水合模型');
        $this->assertSame('bio-of-ada', $profile->bio);

        $post = OrmWPost::find(1);
        $owner = $post->user()->getResults();
        $this->assertInstanceOf(OrmWUser::class, $owner, 'BelongsTo 反向水合');
        $this->assertSame('ada', $owner->name);

        $empty = OrmWUser::find(2)->posts()->getResults();
        $this->assertSame([], $empty, '无子行 → 空数组');
    }

    public function testRelationGettersExposeQueryMetadata(): void
    {
        $relation = OrmWUser::find(1)->posts();
        $this->assertInstanceOf(HasMany::class, $relation);
        $this->assertNotNull($relation->getQuery(), 'getQuery 可用于关系内自定义');
        $this->assertSame('user_id', $relation->getForeignKey());
        $this->assertSame('orm_posts', $relation->getRelated()->getTable());
        $this->assertSame('id', $relation->getLocalKey());

        $userRelation = OrmWPost::find(1)->user();
        $this->assertInstanceOf(BelongsTo::class, $userRelation);
        $this->assertSame('user_id', $userRelation->getForeignKey());
        $this->assertNotNull($userRelation->getOwnerKey());
    }

    public function testLoadAttachesHydratedRelationsAfterFetch(): void
    {
        $user = OrmWUser::find(1);
        $this->assertFalse($user->hasRelation('posts'), '未加载 → hasRelation false');

        $user->load('posts');
        $this->assertTrue($user->hasRelation('posts'), 'load 后关系已挂载');
        $posts = $user->getRelation('posts');
        $this->assertInstanceOf(OrmWPost::class, $posts[0]);
        $this->assertSame('p1', $posts[0]->title);

        $user->load('profile');
        $this->assertInstanceOf(OrmWProfile::class, $user->getRelation('profile'));
    }

    public function testBelongsToManyPivotAccess(): void
    {
        $tags = OrmWUser::find(1)->tags()->getResults();
        $this->assertCount(2, $tags);
        $this->assertInstanceOf(OrmWTag::class, $tags[0]);
        $this->assertSame('php', $tags[0]->name);

        $relation = OrmWUser::find(1)->tags();
        $this->assertSame('orm_user_tag', $relation->getTable());
        $this->assertSame('user_id', $relation->getForeignPivotKey());
        $this->assertSame('tag_id', $relation->getRelatedPivotKey());

        $relation->attach(1);
        $this->assertSame(3, Db::table('orm_user_tag')->count(), 'attach 幂等插入由唯一约束或重复行决定，此处记录追加');
    }

    /* ==================== QueryBuilder：select 展平 / upsert / 分页 / copy ==================== */

    public function testSelectFlattensArrayColumns(): void
    {
        // 曾：select(['id','title']) 嵌套成 "Array" 字面量 → SELECT Array
        $rows = Db::table('orm_posts')->select(['id', 'title'])->get();
        $this->assertCount(2, $rows);
        $this->assertSame(['id', 'title'], array_keys($rows[0]));

        $count = Db::table('orm_posts')->select(['id'])->count();
        $this->assertSame(2, $count);

        $sum = Db::table('orm_posts')->select(['id'])->sum('user_id');
        $this->assertNotSame(0, $sum, 'sum 在 select 之后仍能执行');
    }

    public function testUpsertReturnsTrueOnBothBranches(): void
    {
        // 曾：尾部拼 RETURNING 语义在 PDO 下 TypeError，强制转 bool 失败
        $updated = Db::table('orm_users')->upsert(
            ['name' => 'ada', 'role' => 'owner'],
            ['name'],
            ['role'],
        );
        $this->assertTrue($updated, '命中唯一键 → 更新分支返回 true');
        $this->assertSame('owner', Db::table('orm_users')->where('name', 'ada')->value('role'));

        $inserted = Db::table('orm_users')->upsert(
            ['name' => 'dave', 'role' => 'member'],
            ['name'],
            ['role'],
        );
        $this->assertTrue($inserted, '未命中 → 插入分支同样返回 true');
        $this->assertSame(4, count(OrmWUser::all()));
    }

    public function testFirstOrCreateOnBuilderReturnsRowTruthiness(): void
    {
        $row = Db::table('orm_users')->firstOrCreate(['name' => 'ada']);
        $this->assertIsArray($row);
        $this->assertSame('ada', $row['name'], '已存在 → 回查行');

        $created = Db::table('orm_users')->firstOrCreate(['name' => 'frank'], ['role' => 'member']);
        $this->assertIsArray($created);
        $this->assertSame('frank', $created['name']);
        $this->assertNotSame('', $created['id'] ?? '', '插入路径拿到主键');
    }

    public function testSimplePaginateShapeAndHasMore(): void
    {
        $first = Db::table('orm_users')->orderBy('id')->simplePaginate(1, 2);
        $this->assertSame(
            ['per_page', 'current_page', 'has_more', 'items'],
            array_keys($first),
        );
        $this->assertTrue($first['has_more']);
        $this->assertCount(2, $first['items']);

        $second = Db::table('orm_users')->orderBy('id')->simplePaginate(2, 2);
        $this->assertFalse($second['has_more'], '末页 has_more=false');
        $this->assertCount(1, $second['items'], '种子三行：第二页剩一行');

        $third = Db::table('orm_users')->orderBy('id')->simplePaginate(3, 2);
        $this->assertCount(0, $third['items'], '越界页空 items');
    }

    public function testCopyPreservesPrimaryKeyAndIsIndependent(): void
    {
        $base = Db::table('orm_users')->where('name', 'ada');
        $copy = $base->copy();

        $row = $copy->find(1);
        $this->assertIsArray($row, 'copy 后 primaryKey 未丢（曾手拷漏字段 → find 走错主键）');
        $this->assertSame('ada', $row['name']);

        $copy->where('role', 'owner');
        $this->assertSame(1, $base->where('role', 'admin')->count(), '原查询不受 copy 后追加条件影响');
    }

    /* ==================== 纯函数：Str ==================== */

    public function testStrCamelAndSnake(): void
    {
        $this->assertSame('userProfile', \Kode\Database\Support\Str::camel('user_profile'));
        $this->assertSame('userProfile', \Kode\Database\Support\Str::camel('user-profile'));
        // 无分隔符输入：lcfirst 学院式 camel（与 Laravel camel() 同口径，关系方法名查找按此约定）
        $this->assertSame('userProfile', \Kode\Database\Support\Str::camel('UserProfile'));
        $this->assertSame('user_profile', \Kode\Database\Support\Str::snake('userProfile'));
        $this->assertSame('user_profile', \Kode\Database\Support\Str::snake('UserProfile'));
        $this->assertSame('orm_user_tag', \Kode\Database\Support\Str::snake('OrmUserTag'));
    }

    /* ==================== 工具 ==================== */

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
}

/**
 * @property string $name
 * @property string $role
 */
class OrmWUser extends Model
{
    protected string $table = 'orm_users';

    protected bool $timestamps = false;

    /** @var array<string, mixed> 批量赋值之外的默认值（hasDefault/defaults 语义） */
    protected array $defaults = ['role' => 'member'];

    public function posts(): HasMany
    {
        return $this->hasMany(OrmWPost::class, 'user_id', 'id');
    }

    public function profile(): HasOne
    {
        return $this->hasOne(OrmWProfile::class, 'user_id', 'id');
    }

    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(OrmWTag::class, 'orm_user_tag', 'user_id', 'tag_id');
    }
}

/**
 * @property string $title
 */
class OrmWPost extends Model
{
    protected string $table = 'orm_posts';

    protected bool $timestamps = false;

    public function user(): BelongsTo
    {
        return $this->belongsTo(OrmWUser::class, 'user_id', 'id');
    }
}

/**
 * @property string $bio
 */
class OrmWProfile extends Model
{
    protected string $table = 'orm_profiles';

    protected bool $timestamps = false;
}

/**
 * @property string $name
 */
class OrmWTag extends Model
{
    protected string $table = 'orm_tags';

    protected bool $timestamps = false;
}
