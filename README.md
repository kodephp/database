# Kode\Database

轻量级数据库适配器，兼容 Laravel、ThinkPHP、Hyperf ORM，支持 **多进程、多线程、协程** 环境下的数据库操作。

## 特性

- **全 ORM 支持**：一对一、一对多、多对多、多态关联、预加载
- **多数据库支持**：主从、读写分离自动路由、跨库关联查询
- **分库分表**：按年月、按哈希、按后缀、范围映射、自动路由
- **连接池管理**：协程上下文隔离，支持 Fiber
- **事件监听**：SQL 监听、事务事件、模型事件钩子
- **Schema 定义**：表结构构建器
- **获取器/修改器**：类型转换、属性访问
- **软删除**：软删除恢复机制
- **模型事件**：钩子函数、观察者模式
- **批量操作**：Chunk 分块、Upsert、批量插入优化
- **行锁定**：FOR UPDATE、共享锁支持
- **兼容主流框架**：Laravel、ThinkPHP、Hyperf、Webman

## 环境要求

- PHP >= 8.1
- 兼容 Swoole、Workerman、RoadRunner 等常驻内存环境

## 安装

```bash
composer require kode/database
```

---

## 配置

### 基础配置

```php
use Kode\Database\Db\Db;

// 初始化默认连接
Db::setConfig([
    'driver' => 'mysql',
    'host' => '127.0.0.1',
    'port' => 3306,
    'database' => 'main_db',
    'username' => 'root',
    'password' => '',
    'pool' => ['max' => 10, 'min' => 2]
]);
```

### 多数据库配置

```php
// 默认连接（主库）
Db::setConfig([
    'driver' => 'mysql',
    'host' => '127.0.0.1',
    'database' => 'main_db',
    'username' => 'root',
    'password' => '',
    'pool' => ['max' => 10, 'min' => 2]
]);

// 添加从库（读库）
Db::addConnection('slave', [
    'driver' => 'mysql',
    'host' => '127.0.0.2',
    'database' => 'main_db',
    'username' => 'root',
    'password' => '',
]);

// 添加订单库
Db::addConnection('order_db', [
    'driver' => 'mysql',
    'host' => '127.0.0.3',
    'database' => 'order_db',
    'username' => 'root',
    'password' => '',
]);

// 添加库存库（不同服务器）
Db::addConnection('stock_db', [
    'driver' => 'mysql',
    'host' => '192.168.1.100',
    'database' => 'stock_db',
    'username' => 'root',
    'password' => '',
]);
```

---

## 读写分离自动路由

### 启用读写分离

```php
// 配置主从数据库
Db::setConfig([
    'driver' => 'mysql',
    'host' => '127.0.0.1',      // 主库 IP
    'database' => 'main_db',
    'username' => 'root',
    'password' => '',
]);

Db::addConnection('slave', [
    'driver' => 'mysql',
    'host' => '127.0.0.2',      // 从库 IP
    'database' => 'main_db',
    'username' => 'root',
    'password' => '',
]);

// 启用读写分离
Db::enableReadWriteSplit('slave');
```

### 自动路由规则

```php
// 启用后，以下操作自动路由：

// 读操作 -> 从库 (slave)
Db::table('users')->get();           // 从库查询
Db::table('users')->first();          // 从库查询
Db::table('users')->find(1);          // 从库查询
Db::table('users')->exists();         // 从库查询
Db::table('users')->count();          // 从库查询
Db::select('SELECT * FROM users');    // 从库查询

// 写操作 -> 主库 (default)
Db::tableWrite('users')->insert([...]); // 主库插入
Db::tableWrite('users')->where('id', 1)->update([...]); // 主库更新
Db::tableWrite('users')->where('id', 1)->delete();       // 主库删除

// 事务自动使用主库
Db::transaction(function () {
    Db::table('users')->insert([...]); // 主库
    Db::table('orders')->insert([...]); // 主库
});
```

### 强制使用主库

```php
// 使用 tableWrite 强制使用主库进行写操作
Db::tableWrite('users')->insert(['name' => 'test']);

// 即使是读操作也可以强制使用主库
Db::tableWrite('users')->where('id', 1)->first();

// 禁用读写分离
Db::disableReadWriteSplit();
```

---

## 多数据库支持

### 使用指定连接

```php
use Kode\Database\Db\Db;

// 使用默认连接
Db::table('users')->get();

// 使用从库查询（读写分离场景）
Db::connection('slave')->table('users')->get();

// 使用订单库
Db::connection('order_db')->table('orders')->get();

// 使用库存库
Db::connection('stock_db')->table('products')->get();

// 直接获取连接实例
$conn = Db::connection('order_db');
$conn->table('orders')->where('status', 1)->get();
```

### 连接管理

```php
// 设置默认连接
Db::setDefaultConnection('default');

// 获取默认连接名
$name = Db::getDefaultConnection();

// 获取所有连接配置
$connections = Db::getConnections();

// 检查连接是否存在
if (Db::hasConnection('order_db')) {
    // ...
}

// 重新连接（清除连接池）
Db::reconnect('slave');
Db::reconnect(); // 重新连接默认连接
```

### 切换数据库

```php
// 切换到其他数据库
Db::useDatabase('shop')->table('products')->get();

// 指定连接的指定数据库
$conn = Db::connection('slave');
$conn->useDatabase('report_db');
$conn->table('sales')->get();

// Connection 链式调用
Db::connection('default')
    ->useDatabase('shop')
    ->table('products')
    ->where('status', 1)
    ->get();
```

### 跨库关联查询

```php
// 使用 crossJoin 进行跨库 Join
// 语法: Db::crossJoin('主表', 'db.表名', '主表字段', '操作符', '副表字段')
Db::crossJoin('users', 'shop.products', 'users.shop_id', '=', 'shop.id')
    ->select(['users.name', 'shop.products.name as product_name'])
    ->get();

// Inner Join 跨库
Db::connection('default')
    ->table('users')
    ->join('order_db.orders', 'users.id', '=', 'orders.user_id')
    ->get();

// Left Join 跨库
Db::connection('default')
    ->table('users')
    ->leftJoin('shop.cart_items', 'users.id', '=', 'cart_items.user_id')
    ->get();
```

---

## 分库分表

### 分片策略概述

| 策略 | 说明 | 适用场景 |
|------|------|----------|
| `hash` | 按分片键哈希取模 | 用户行为数据、按用户ID分表 |
| `suffix` | 按分片键直接作为后缀 | 固定分片数量、按编号分表 |
| `range` | 按范围映射 | 按年月分表、按状态分表 |
| `default` | 不分片 | 单表场景 |

### Db 静态类分片方法

```php
// 按哈希分表（推荐）
// $userId % 16 = 0-15
$table = Db::tableByHash('user_actions', $userId, 16);
// 结果: user_actions_5

// 按后缀分表
$table = Db::tableBySuffix('user_orders', 5);
// 结果: user_orders_5

// 按年月分表
$table = Db::getShardingTable('orders', date('Ym'));
// 结果: orders_202504

// 动态表名
Db::table(Db::tableByHash('user_actions', $userId, 16))
    ->where('user_id', $userId)
    ->get();
```

### 智能分片路由

```php
// 根据分片键自动计算表名
// 格式: Db::routeSharding(表名, 分片键, 策略, 分片数, 范围映射)

// Hash 策略
$table = Db::routeSharding('orders', $userId, 'hash', 16);
// $userId=12345 -> orders_13

// Range 策略（按年月）
$table = Db::routeSharding(
    'orders',
    '2024',
    'range',
    0,
    ['2023' => 0, '2024' => 1, '2025' => 2]
);
// 结果: orders_1

// Suffix 策略
$table = Db::routeSharding('logs', 5, 'suffix', 100);
// 结果: logs_5
```

### 批量跨分片操作

```php
// 查询用户在所有分表的数据
$userId = 12345;
$shardingCount = 16;
$allData = [];

for ($i = 0; $i < $shardingCount; $i++) {
    $table = "user_actions_{$i}";
    $data = Db::table($table)->where('user_id', $userId)->get();
    $allData = array_merge($allData, $data);
}

// 使用 crossSharding 辅助方法
$results = Db::crossSharding(
    'user_actions',
    function ($table, $index) use ($userId) {
        return Db::table($table)->where('user_id', $userId)->get();
    },
    16,
    'hash'
);

// 合并所有分片结果
$allData = array_merge(...array_values($results));
```

### 分库分表查询示例

```php
// 1. 用户行为分析（按 userId 哈希分 16 张表）
class UserAction extends Model
{
    protected string $table = 'user_actions';
    protected int $shardingCount = 16;
    protected string $shardingStrategy = 'hash';
    protected string $shardingKey = 'user_id';
}

// 查询单个用户行为
$userId = 12345;
$table = UserAction::routeSharding('user_actions', $userId, 'hash', 16);
Db::table($table)->where('user_id', $userId)->get();

// 查询所有分片用户行为
$allActions = UserAction::allShards(function ($query, $index) use ($userId) {
    return $query->where('user_id', $userId)->get();
});

// 2. 订单表（按年月分表）
class Order extends Model
{
    protected string $table = 'orders';
    protected string $shardingStrategy = 'range';
    protected array $rangeMap = [
        '2023' => 0,
        '2024' => 1,
        '2025' => 2,
    ];
}

// 查询某年订单
$year = '2024';
$table = Order::routeSharding('orders', $year, 'range', 0, Order::$rangeMap);
Db::table($table)->where('user_id', $userId)->get();

// 3. 日志表（按月分表）
$month = date('Ym'); // 202504
$table = Db::getShardingTable('system_logs', $month);
Db::table($table)->orderBy('id', 'DESC')->limit(100)->get();

// 4. 批量写入分表
$userId = 12345;
$tableIndex = $userId % 100;
Db::table("user_data_{$tableIndex}")->insert([
    'user_id' => $userId,
    'action' => 'login',
    'created_at' => date('Y-m-d H:i:s')
]);
```

---

## 查询构建器

### 基础查询

```php
// 获取所有
Db::table('users')->get();

// 获取第一条
Db::table('users')->first();

// 通过主键查找
Db::table('users')->find(1);

// 选择列
Db::table('users')->select('name', 'email')->get();

// 去重
Db::table('users')->distinct()->select('name')->get();

// where 条件
Db::table('users')->where('status', '=', 1)->get();
Db::table('users')->whereIn('id', [1, 2, 3])->get();
Db::table('users')->whereNull('deleted_at')->get();
Db::table('users')->whereBetween('age', 18, 30)->get();

// 条件查询
Db::table('users')
    ->when($search, fn($q) => $q->where('name', 'like', "%{$search}%"))
    ->get();

// orWhere
Db::table('users')->where('status', '=', 1)->orWhere('type', '=', 'admin')->get();

// 排序分页
Db::table('users')->orderBy('id', 'DESC')->limit(10)->get();
Db::table('users')->paginate(1, 15);

// 聚合
Db::table('users')->count();
Db::table('users')->sum('balance');

// 检查存在
Db::table('users')->where('email', $email)->exists();
```

### ThinkPHP 风格方法

```php
// field - 选择字段（支持字符串和数组）
Db::table('users')->field('name,email')->get();
Db::table('users')->field(['name', 'email'])->get();

// order - 排序（支持字符串和数组）
Db::table('users')->order('id desc')->get();
Db::table('users')->order('id', 'desc')->get();
Db::table('users')->order(['id' => 'desc', 'created_at' => 'asc'])->get();

// page - 分页（ThinkPHP 风格）
Db::table('users')->page(1, 15)->get();  // 第一页，每页15条

// limit - 限制（支持偏移量）
Db::table('users')->limit(10)->get();      // 前10条
Db::table('users')->limit(10, 20)->get();  // 跳过20条，取10条

// group - 分组
Db::table('orders')->group('status')->get();

// alias - 表别名
Db::table('users')->alias('u')->join('orders o', 'u.id', '=', 'o.user_id')->get();

// distinct - 去重
Db::table('users')->distinct()->field('name')->get();

// fetchSql - 返回 SQL 不执行
$sql = Db::table('users')->where('id', 1)->fetchSql();

// 聚合函数
Db::table('users')->count();
Db::table('users')->sum('balance');
Db::table('users')->avg('score');
Db::table('users')->max('price');
Db::table('users')->min('price');
```

### 表连接 (JOIN)

```php
// Inner Join
Db::table('users')->join('profiles', 'users.id', '=', 'profiles.user_id')->get();

// Left Join
Db::table('users')->leftJoin('profiles', 'users.id', '=', 'profiles.user_id')->get();

// Right Join
Db::table('users')->rightJoin('profiles', 'users.id', '=', 'profiles.user_id')->get();

// 跨库 Join
Db::connection('default')
    ->table('users')
    ->join('order_db.orders', 'users.id', '=', 'orders.user_id')
    ->get();

// 多个 Join
Db::table('users')
    ->join('profiles', 'users.id', '=', 'profiles.user_id')
    ->leftJoin('orders', 'users.id', '=', 'orders.user_id')
    ->where('users.status', 1)
    ->get();
```

### 增删改

```php
// 插入
Db::table('users')->insert(['name' => 'test', 'email' => 'test@example.com']);
Db::table('users')->insertAll([
    ['name' => 'a', 'email' => 'a@example.com'],
    ['name' => 'b', 'email' => 'b@example.com']
]);

// 更新
Db::table('users')->where('id', '=', 1)->update(['name' => 'newname']);

// 自增自减
Db::table('users')->where('id', '=', 1)->inc('views');
Db::table('users')->where('id', '=', 1)->inc('score', 10);
Db::table('users')->where('id', '=', 1)->dec('balance', 100);

// 删除
Db::table('users')->where('id', '=', 1)->delete();

// 批量删除
Db::table('users')->whereIn('id', [1, 2, 3])->delete();
```

### 批量操作

```php
// Chunk 分块处理（适合大批量数据）
Db::table('orders')->orderBy('id')->chunk(function ($orders) {
    foreach ($orders as $order) {
        process($order);
    }
}, 1000);

// 游标查询（适合大结果集遍历）
Db::table('users')->cursor(function ($user) {
    sendEmail($user);
}, 500);

// 批量插入（自动分块）
$records = [];
for ($i = 0; $i < 10000; $i++) {
    $records[] = ['name' => "user_{$i}", 'email' => "user_{$i}@example.com"];
}
$insertedCount = Db::chunkInsert('users', $records, 1000);

// Upsert - 插入或更新（基于唯一键）
Db::table('users')->upsert(
    ['email' => 'test@example.com', 'name' => 'updated'],
    ['email'],                        // 唯一键
    ['name']                          // 更新时只更新 name 字段
);

// 批量 Upsert
Db::table('users')->upsertAll($records, ['email']);

// firstOrCreate
$user = Db::table('users')->firstOrCreate(
    ['email' => 'test@example.com'],
    ['name' => 'new user']
);

// updateOrCreate
Db::table('users')->updateOrCreate(
    ['email' => 'test@example.com'],
    ['name' => 'updated']
);

// 多个聚合查询
$result = Db::table('orders')->aggregates([
    'count' => '*',
    'sum' => 'total',
    'avg' => 'total',
    'max' => 'total',
    'min' => 'total'
]);
```

### Db 静态类增强方法

```php
// 批量执行 SQL
$results = Db::batch([
    'users' => 'SELECT * FROM users LIMIT 10',
    'count' => ['SELECT COUNT(*) as total FROM users', []],
]);

// 批量插入
$records = [['name' => 'a'], ['name' => 'b'], ['name' => 'c']];
$count = Db::batchInsert('users', $records);

// 批量更新
$data = [
    ['id' => 1, 'name' => 'updated1'],
    ['id' => 2, 'name' => 'updated2'],
];
$affected = Db::batchUpdate('users', $data);

// 清空表
Db::truncate('users');

// 获取数据库版本
$version = Db::getVersion();

// 检查表是否存在
$exists = Db::tableExists('users');

// 获取表结构
$columns = Db::getTableColumns('users');

// 表达式查询
$result = Db::raw('SELECT NOW() as now');

// 批量 Upsert
Db::upsert('users', ['email' => 'test@example.com', 'name' => 'test'], ['email']);
```

### 行锁定

```php
// 悲观锁（FOR UPDATE）
Db::table('users')
    ->where('id', 1)
    ->lock('FOR UPDATE')
    ->first();

// 共享锁
Db::table('users')
    ->where('id', 1)
    ->sharedLock()
    ->first();

// 在事务中使用
Db::transaction(function () {
    $user = Db::table('users')
        ->where('balance', '>=', 100)
        ->lock('FOR UPDATE')
        ->first();

    if ($user) {
        Db::table('users')
            ->where('id', $user['id'])
            ->dec('balance', 100);
    }
});
```

### 事务

```php
// 自动事务
Db::transaction(function () {
    Db::table('users')->insert(['name' => 'test']);
    Db::table('accounts')->insert(['user_id' => 1, 'balance' => 100]);
});

// 手动事务
Db::beginTransaction();
try {
    Db::table('users')->insert(['name' => 'test']);
    Db::table('orders')->insert(['user_id' => 1, 'total' => 100]);
    Db::commit();
} catch (\Throwable $e) {
    Db::rollback();
    throw $e;
}

// 指定连接的事务
Db::connection('order_db')->transaction(function ($conn) {
    $conn->table('orders')->insert([...]);
});

// 跨库事务（需要分布式事务支持）
Db::transaction(function () {
    Db::connection('default')->table('users')->insert([...]);
    Db::connection('order_db')->table('orders')->insert([...]);
});
```

---

## Model 模型

### 基本定义

```php
use Kode\Database\Model\Model;

class User extends Model
{
    protected string $table = 'users';
    protected string $primaryKey = 'id';
    protected array $fillable = ['name', 'email', 'status'];
    protected array $guarded = ['password'];
    protected bool $timestamps = true;
    protected array $casts = [
        'status' => 'int',
        'balance' => 'float',
    ];
}
```

### 指定数据库连接

```php
class Order extends Model
{
    protected string $table = 'orders';
    protected string $connection = 'order_db';
    protected string $database = 'order_db';
}
```

### 分表模型定义

```php
class UserAction extends Model
{
    protected string $table = 'user_actions';
    protected int $shardingCount = 16;
    protected string $shardingStrategy = 'hash';
    protected string $shardingKey = 'user_id';
}

// 查询时指定分片
$userId = 12345;
$table = UserAction::routeSharding('user_actions', $userId, 'hash', 16);

// 使用 crossSharding 查询所有分片
$allActions = UserAction::allShards(function ($query, $index) use ($userId) {
    return $query->where('user_id', $userId)->get();
});
```

### 增删改查

```php
// 查找
User::find(1);
User::findOrFail(1);
User::first();
User::all();

// 创建
User::create(['name' => 'test', 'email' => 'test@example.com']);

// 更新
$user = User::find(1);
$user->name = 'newname';
$user->save();

// 删除
$user->delete();
$user->forceDelete();

// 恢复
$user->restore();

// 查找或创建
User::firstOrCreate(['email' => 'test@test.com'], ['name' => 'new']);
User::updateOrCreate(['email' => 'test@test.com'], ['name' => 'updated']);
```

### 快捷查询

```php
User::where('status', '=', 1)->get();
User::whereIn('id', [1, 2, 3])->get();
User::orderBy('id', 'DESC')->get();
User::paginate(1, 15);
User::count();
User::sum('balance');
```

### Model 增强方法

```php
// 批量操作
User::insertBatch($records);      // 批量插入
User::updateBatch($data);        // 批量更新
User::upsert($data, ['email']); // Upsert
User::upsertBatch($records, ['email']); // 批量 Upsert

// 分页查询
User::paginate(1, 15, 'id', 'DESC');      // 自定义排序分页
User::simplePaginate(1, 15);              // 简单分页（只返回数据和总数）

// 大数据遍历
User::chunk(function ($users) {
    foreach ($users as $user) {
        process($user);
    }
}, 1000);

User::cursor(function ($user) {
    sendEmail($user);
}, 500);

// 查找多个
User::findMany([1, 2, 3]);

// 聚合查询
User::aggregates(['count' => '*', 'sum' => 'balance']);

// 获取字段值
User::value(['id' => 1], 'name');  // 获取单条记录的单字段值
User::pluck('name');              // 获取单列值列表
User::pluck('name', ['status' => 1]); // 带条件获取

// 批量删除
User::destroy([1, 2, 3]);           // 软删除多个
User::deleteBatch([1, 2, 3]);       // 逻辑删除多个
User::forceDeleteBatch([1, 2, 3]);  // 强制删除多个
```

### Model 跨库操作

```php
// 使用 on() 指定连接
User::on('slave')->find(1);

// 使用 onDatabase() 指定数据库
User::onDatabase('shop')->where('status', 1)->get();

// 链式调用
$user = User::on('order_db')
    ->onDatabase('order_db')
    ->setShardingKey($userId)
    ->find(1);
```

---

## 模型事件 (钩子函数)

### 支持的事件

| 事件 | 说明 | 返回值 |
|------|------|--------|
| `creating` | 创建前 | `false` 取消创建 |
| `created` | 创建后 | - |
| `updating` | 更新前 | `false` 取消更新 |
| `updated` | 更新后 | - |
| `saving` | 保存前 | `false` 取消保存 |
| `saved` | 保存后 | - |
| `deleting` | 删除前 | `false` 取消删除 |
| `deleted` | 删除后 | - |
| `forceDeleting` | 强制删除前 | `false` 取消删除 |
| `forceDeleted` | 强制删除后 | - |
| `restoring` | 恢复前 | `false` 取消恢复 |
| `restored` | 恢复后 | - |

### 闭包方式

```php
use Kode\Database\Model\Model;

User::creating(function (Model $user) {
    if (empty($user->name)) return false;
    $user->created_at = date('Y-m-d H:i:s');
});

User::created(function (Model $user) {
    Log::info("创建用户: {$user->id}");
});

User::updating(function (Model $user) {
    Log::info("更新用户: {$user->id}");
});
```

### 观察者模式

```php
use Kode\Database\Model\Model;
use Kode\Database\Model\Observer;

class UserObserver extends Observer
{
    public function created(Model $user): void
    {
        Log::info("创建用户: {$user->id}");
    }

    public function updated(Model $user): void
    {
        Log::info("更新用户: {$user->id}");
    }

    public function deleted(Model $user): void
    {
        Log::info("删除用户: {$user->id}");
    }
}

// 注册观察者
User::observe(UserObserver::class);

// 移除观察者
User::observe(UserObserver::class, false);
```

---

## 软删除

```php
class User extends Model
{
    use \Kode\Database\Model\Concerns\SoftDeletes;
    protected string $softDeleteField = 'deleted_at';
}

$user->delete();       // 软删除
$user->forceDelete();  // 硬删除
$user->restore();      // 恢复

// 查询
User::find(1);              // 不包含已删除
User::withTrashed()->find(1);  // 包含已删除
User::onlyTrashed()->find(1);  // 仅已删除
```

---

## 获取器/修改器

```php
class User extends Model
{
    // 获取器 - 访问 $user->status_text 时自动调用
    public function getStatusTextAttribute(): string
    {
        return match ($this->status) {
            1 => '启用',
            0 => '禁用',
            default => '未知',
        };
    }

    // 获取器 - 类型转换
    public function getOptionsAttribute($value): array
    {
        return json_decode($value, true) ?? [];
    }

    // 修改器 - 设置 $user->password = 'xxx' 时自动加密
    public function setPasswordAttribute(string $value): void
    {
        $this->attributes['password'] = password_hash($value, PASSWORD_DEFAULT);
    }

    // 类型转换
    protected array $casts = [
        'options' => 'array',
        'is_active' => 'bool',
        'balance' => 'float',
        'created_at' => 'datetime',
    ];
}
```

---

## 关联关系

### 定义关联

```php
class User extends Model
{
    // 一对一（正向）
    public function profile()
    {
        return $this->hasOne(Profile::class);
    }

    // 一对多
    public function posts()
    {
        return $this->hasMany(Post::class);
    }

    // 属于（反向一对一）
    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    // 多对多
    public function roles()
    {
        return $this->belongsToMany(Role::class);
    }

    // 多态一对一
    public function avatar()
    {
        return $this->morphOne(Avatar::class, 'imageable');
    }

    // 多态一对多
    public function comments()
    {
        return $this->morphMany(Comment::class, 'commentable');
    }

    // 多态
    public function image()
    {
        return $this->morphTo();
    }
}
```

### 使用关联

```php
// 预加载
$users = User::with('profile,posts')->get();

// 关联计数（自动加载关联数量）
$users = User::withCount('posts')->get();
foreach ($users as $user) {
    echo $user->posts_count; // 帖子数量
}

// 带条件的关联计数
$users = User::withCount(['posts' => function ($q) {
    $q->where('status', 1);
}])->get();

// 延迟预加载
$user = User::find(1);
$user->load('profile');

// 延迟预加载（带条件）
$user->loadWhere('posts', function ($q) {
    $q->where('status', 1);
});

// 合并预加载
$users = User::with('profile')->get();
$users->mergeLoad('posts');

// has - 筛选有关联记录的模型
User::has('posts', '>=', 3)->get(); // 至少有3篇帖子的用户
User::has('posts', '>', 0)->get();  // 至少有1篇帖子的用户

// whereHas - 筛选关联满足条件的模型
User::whereHas('posts', fn($q) => $q->where('status', 1))->get();

// doesNotHave - 筛选没有关联记录的模型
User::doesNotHave('posts')->get();

// 多对多操作
$user->roles()->attach($roleId);
$user->roles()->detach($roleId);
$user->roles()->sync([1, 2, 3]);
$user->roles()->toggle([1, 2]);
```

---

## Schema 表结构

### 创建表

```php
use Kode\Database\Schema\Schema;

Schema::create('users', function (Schema $table) {
    $table->id();
    $table->string('name');
    $table->string('email')->unique();
    $table->string('password');
    $table->tinyInteger('status')->default(1);
    $table->decimal('balance', 10, 2)->default(0);
    $table->text('bio')->nullable();
    $table->timestamps();
    $table->softDeletes();
});
```

### 表选项

```php
Schema::create('users', function (Schema $table) {
    $table->id();
    $table->string('name');

    // 表选项
    $table->engine('InnoDB');           // 设置引擎
    $table->charset('utf8mb4');         // 设置字符集
    $table->collation('utf8mb4_unicode_ci'); // 设置排序规则
    $table->comment('用户表');          // 表注释
    $table->autoIncrement(1000);        // 自增初始值
});
```

### 字段类型

```php
Schema::create('demo', function (Schema $table) {
    // 数值类型
    $table->id();               // bigint 自增主键
    $table->increments('id');   // 递增字段
    $table->integer('votes');   // int
    $table->bigInteger('votes'); // bigint
    $table->smallInteger('votes'); // smallint
    $table->tinyInteger('votes', 3); // tinyint
    $table->mediumInteger('votes'); // mediumint
    $table->unsignedInteger('votes'); // 无符号 int
    $table->float('amount', 10, 2); // float
    $table->double('ratio'); // double
    $table->decimal('amount', 10, 2); // decimal
    $table->boolean('is_active'); // tinyint(1)

    // 字符串类型
    $table->string('name', 100); // varchar(100)
    $table->char('code', 10);    // char(10)
    $table->text('content');     // text
    $table->mediumText('content'); // mediumtext
    $table->longText('content');  // longtext

    // 日期时间类型
    $table->date('birthday');    // date
    $table->time('work_time');   // time
    $table->dateTime('created_at'); // datetime
    $table->timestamp('updated_at'); // timestamp
    $table->year('birth_year');  // year

    // 特殊类型
    $table->ipAddress('ip');     // varchar(45)
    $table->macAddress('mac');   // char(17)
    $table->uuid('guid');        // char(36)
    $table->json('options');     // json

    // 常用字段
    $table->rememberToken();      // remember_token varchar(100)
    $table->timestamps();         // created_at, updated_at
    $table->softDeletes();        // deleted_at
});
```

### 修改表

```php
Schema::table('users', function (Schema $table) {
    // 添加字段
    $table->addColumn('string', 'phone', ['length' => 11]);

    // 修改字段
    $table->modifyColumn('name', ['type' => 'varchar', 'length' => 200]);

    // 删除字段
    $table->dropColumn('phone');

    // 添加索引
    $table->index(['email', 'status']);
    $table->uniqueKey(['email'], 'uniq_email');
    $table->primaryKey('id');
});
```

### 判断表/字段是否存在

```php
// 判断表是否存在
$sql = Schema::hasTable('users');

// 判断字段是否存在
$sql = Schema::hasColumn('users', 'email');
```

---

## 事件监听

```php
use Kode\Database\Event\EventManager;
use Kode\Database\Event\SqlListener;
use Kode\Database\Event\SqlEvent;

$listener = new SqlListener();
EventManager::getInstance()->listen(SqlEvent::class, $listener);

Db::table('users')->get();

$sqls = $listener->getSqls();
foreach ($sqls as $sql) {
    echo $sql['sql'] . PHP_EOL;
}
```

### 事务事件

```php
use Kode\Database\Event\TransactionBeginEvent;
use Kode\Database\Event\TransactionCommitEvent;
use Kode\Database\Event\TransactionRollbackEvent;

EventManager::getInstance()->listen(TransactionBeginEvent::class, function ($event) {
    echo "事务开始" . PHP_EOL;
});

EventManager::getInstance()->listen(TransactionCommitEvent::class, function ($event) {
    echo "事务提交" . PHP_EOL;
});

EventManager::getInstance()->listen(TransactionRollbackEvent::class, function ($event) {
    echo "事务回滚" . PHP_EOL;
});
```

---

## 连接池管理

```php
use Kode\Database\Pool\PoolManager;

PoolManager::init($config, 'default');
$stats = PoolManager::getPool()->getStats();

PoolManager::getPool()->cleanup();
PoolManager::getPool()->reset();

// 清除指定连接池
Db::clearPool('slave');

// 获取连接池状态
$stats = [
    'max' => 10,
    'min' => 2,
    'active' => 5,
    'idle' => 5,
];
```

---

## 多进程/多线程/协程支持

### Fiber 协程

```php
$fiber = new Fiber(function () {
    return Db::table('users')->where('status', 1)->get();
});
$result = $fiber->start();
```

### Workerman 多进程

```php
use Workerman\Worker;

$worker = new Worker('http://0.0.0.0:8080');
$worker->count = 4;
$worker->onMessage = function ($connection, $data) {
    $users = Db::table('users')->where('status', 1)->get();
    $connection->send(json_encode($users));
};
Worker::runAll();
```

### 进程池 (kode/process)

```php
use Kode\Process\ProcessPool;

$pool = ProcessPool::create(4);
$results = $pool->map(function ($workerId) {
    return Db::table('users')->count();
});
```

### 并行查询 (kode/parallel)

```php
use Kode\Parallel\Parallel;

$parallel = new Parallel();
$results = $parallel->wait([
    'users' => fn() => Db::table('users')->get(),
    'orders' => fn() => Db::connection('order_db')->table('orders')->get(),
    'products' => fn() => Db::connection('stock_db')->table('products')->get(),
]);

echo $results['users'];
echo $results['orders'];
echo $results['products'];
```

---

## 项目结构

```
src/
├── Connection/          # 数据库连接器
│   ├── ConnectorInterface.php
│   ├── ConnectionFactory.php
│   ├── LaravelConnector.php
│   └── ThinkPHPConnector.php
├── Db/                  # 静态代理类
│   ├── Db.php           # 主类（支持多数据库、分库分表）
│   └── Connection.php   # 连接封装（支持跨库查询）
├── Event/               # 事件系统
│   ├── EventManager.php
│   ├── ListenerInterface.php
│   ├── SqlEvent.php
│   ├── SqlListener.php
│   ├── SqlLogListener.php
│   ├── TransactionBeginEvent.php
│   ├── TransactionCommitEvent.php
│   └── TransactionRollbackEvent.php
├── Exception/           # 异常类
│   ├── ConnectionException.php
│   ├── DatabaseException.php
│   └── QueryException.php
├── Model/               # 模型基类
│   ├── Concerns/        # Traits
│   │   ├── HasAttributes.php      # 获取器/修改器
│   │   ├── QueriesRelationships.php # 关联查询
│   │   ├── SoftDeletes.php        # 软删除
│   │   └── Timestamps.php         # 时间戳
│   ├── Relation/        # 关联类
│   │   ├── BelongsTo.php
│   │   ├── BelongsToMany.php
│   │   ├── HasMany.php
│   │   ├── HasOne.php
│   │   ├── MorphMany.php
│   │   ├── MorphOne.php
│   │   ├── MorphTo.php
│   │   ├── MorphToMany.php
│   │   └── Relation.php
│   ├── Model.php        # 基类（支持分表）
│   ├── ModelEvent.php   # 事件钩子
│   └── Observer.php     # 观察者
├── Pool/                # 连接池
│   ├── ConnectionPool.php
│   ├── PoolInterface.php
│   └── PoolManager.php
├── Query/               # 查询构建器
│   └── QueryBuilder.php
└── Schema/              # 表结构
    ├── Column.php
    ├── ForeignKey.php
    └── Schema.php
```

---

## License

Apache-2.0
