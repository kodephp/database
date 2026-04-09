# Kode\Database

轻量级数据库适配器，兼容 Laravel、ThinkPHP、Hyperf ORM，支持 **多进程、多线程、协程** 环境下的数据库操作。

## 特性

- **全 ORM 支持**：一对一、一对多、多对多、多态关联、预加载
- **多数据库支持**：主从、读写分离、跨库关联查询
- **分库分表**：按年月、按哈希、按后缀、范围映射、自动路由
- **连接池管理**：协程上下文隔离，支持 Fiber
- **事件监听**：SQL 监听、事务事件、模型事件钩子
- **Schema 定义**：表结构构建器
- **获取器/修改器**：类型转换、属性访问
- **软删除**：软删除恢复机制
- **模型事件**：钩子函数、观察者模式
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

### 模型跨库操作

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

// 延迟预加载
$user = User::find(1);
$user->load('profile');

// 多对多操作
$user->roles()->attach($roleId);
$user->roles()->detach($roleId);
$user->roles()->sync([1, 2, 3]);
$user->roles()->toggle([1, 2]);

// 关联查询
User::has('posts', '>=', 3)->get();
User::whereHas('roles', fn($q) => $q->where('name', 'admin'))->get();
```

---

## Schema 表结构

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

Schema::drop('users');

Schema::table('users', function (Schema $table) {
    $table->addColumn('string', 'phone', ['length' => 11]);
    $table->index(['email', 'status']);
});
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
