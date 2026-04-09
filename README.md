# Kode\Database

轻量级数据库适配器，兼容 Laravel、ThinkPHP、Hyperf ORM，支持 **多进程、多线程、协程** 环境下的数据库操作。

## 特性

- **全 ORM 支持**：一对一、一对多、多对多、多态关联
- **连接池管理**：协程上下文隔离，支持 Fiber
- **事件监听**：SQL 监听、事务事件
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

```php
use Kode\Database\Db\Db;

Db::setConfig([
    'driver' => 'laravel',
    'host' => '127.0.0.1',
    'port' => 3306,
    'database' => 'test',
    'username' => 'root',
    'password' => '',
    'pool' => ['max' => 10, 'min' => 2]
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
Db::table('users')->select(['name', 'email'])->get();

// where 条件
Db::table('users')->where('status', '=', 1)->get();
Db::table('users')->where('status', 1)->get();
Db::table('users')->whereIn('id', [1, 2, 3])->get();
Db::table('users')->whereNotIn('id', [4, 5])->get();
Db::table('users')->whereNull('deleted_at')->get();
Db::table('users')->whereNotNull('updated_at')->get();
Db::table('users')->whereBetween('age', 18, 30)->get();

// 条件查询
Db::table('users')
    ->when($search, fn($q) => $q->where('name', 'like', "%{$search}%"))
    ->when($status, fn($q) => $q->where('status', '=', $status))
    ->get();

// 排序分页
Db::table('users')->orderBy('id', 'DESC')->limit(10)->get();
Db::table('users')->offset(10)->limit(10)->get();

// 聚合
Db::table('users')->count();
Db::table('users')->sum('balance');
Db::table('users')->avg('balance');
Db::table('users')->max('balance');
Db::table('users')->min('balance');

// 分页
Db::table('users')->paginate(1, 15);

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
Db::table('users')->rightJoin('posts', 'users.id', '=', 'posts.user_id')->get();
```

### 联合查询 (UNION)

```php
$query1 = Db::table('users')->select('name');
$query2 = Db::table('admins')->select('name');

// Union
Db::table('users')->select('name')->union($query2)->get();

// Union All
Db::table('users')->select('name')->unionAll($query2)->get();
```

### 增删改

```php
// 插入
Db::table('users')->insert(['name' => 'test']);
Db::table('users')->insertAll([['name' => 'a'], ['name' => 'b']]);

// 更新
Db::table('users')->where('id', '=', 1)->update(['name' => 'newname']);

// 自增自减
Db::table('users')->where('id', '=', 1)->inc('views');
Db::table('users')->where('id', '=', 1)->dec('balance', 100);

// 删除
Db::table('users')->where('id', '=', 1)->delete();
```

### 原生 SQL

```php
Db::select('SELECT * FROM users WHERE id = ?', [1]);
Db::insert('INSERT INTO users (name) VALUES (?)', ['test']);
Db::update('UPDATE users SET name = ? WHERE id = ?', ['test', 1]);
Db::delete('DELETE FROM users WHERE id = ?', [1]);
Db::statement('DROP TABLE IF EXISTS users');
```

### 事务

```php
Db::transaction(function () {
    Db::table('users')->insert(['name' => 'test']);
    Db::table('accounts')->insert(['user_id' => 1, 'balance' => 100]);
});

// 手动控制
Db::beginTransaction();
try {
    // ...
    Db::commit();
} catch (\Throwable $e) {
    Db::rollback();
    throw $e;
}
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
    protected string $dateFormat = 'Y-m-d H:i:s';
    protected array $casts = [
        'status' => 'int',
        'balance' => 'float',
        'is_active' => 'bool',
        'options' => 'array',
    ];
}
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

// 批量更新
User::where('status', '=', 1)->update(['status' => 2]);

// 删除
$user->delete();
$user->forceDelete();

// 软删除恢复
$user->restore();

// 查找或创建
User::firstOrCreate(['email' => 'test@test.com'], ['name' => 'new']);
User::updateOrCreate(['email' => 'test@test.com'], ['name' => 'updated']);
```

### 快捷查询方法

```php
// where 条件
User::where('status', '=', 1)->get();
User::where('status', 1)->get();
User::whereIn('id', [1, 2, 3])->get();
User::whereNull('deleted_at')->get();
User::whereNotNull('updated_at')->get();

// 排序
User::orderBy('id', 'DESC')->get();

// 分页
User::paginate(1, 15);

// 聚合
User::count();
User::sum('balance');
User::avg('balance');
User::max('balance');
User::min('balance');
```

---

## 模型事件 (钩子函数)

### 支持的事件

| 事件 | 说明 |
|------|------|
| `creating` | 创建前 |
| `created` | 创建后 |
| `updating` | 更新前 |
| `updated` | 更新后 |
| `saving` | 保存前（插入/更新） |
| `saved` | 保存后（插入/更新） |
| `deleting` | 删除前 |
| `deleted` | 删除后 |
| `restoring` | 恢复前（软删除） |
| `restored` | 恢复后（软删除） |
| `forceDeleting` | 强制删除前 |
| `forceDeleted` | 强制删除后 |

### 闭包方式注册

```php
class User extends Model
{
    protected string $table = 'users';
}

// 创建前 - 验证数据
User::creating(function (Model $user) {
    if (empty($user->name)) {
        return false; // 返回 false 阻止创建
    }
});

// 创建后 - 记录日志
User::created(function (Model $user) {
    Log::info("用户创建: {$user->id}");
});

// 更新前 - 检查权限
User::updating(function (Model $user) {
    if (!$user->canUpdate()) {
        return false;
    }
});

// 删除前 - 检查关联
User::deleting(function (Model $user) {
    if ($user->hasOrders()) {
        return false;
    }
});

// 恢复后 - 发送通知
User::restored(function (Model $user) {
    Notification::send($user->email, '账号已恢复');
});
```

### 观察者模式

```php
use Kode\Database\Model\Model;
use Kode\Database\Model\Observer;

class UserObserver extends Observer
{
    public function creating(Model $user): void
    {
        $user->created_at = date('Y-m-d H:i:s');
    }

    public function created(Model $user): void
    {
        Log::info("用户创建: {$user->id}");
    }

    public function updating(Model $user): void
    {
        Log::info("用户更新: {$user->id}");
    }

    public function deleted(Model $user): void
    {
        Log::info("用户删除: {$user->id}");
    }
}

// 注册观察者
User::observe(UserObserver::class);

// 或者传入实例
User::observe(new UserObserver());
```

### 清除事件

```php
// 清除单个事件
User::clearEvent('creating');

// 清除所有事件
User::clearAllEvent();

// 清除观察者
User::clearObserver();
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

// 查询时自动排除软删除
User::find(1);              // 不包含已删除
User::withTrashed()->find(1);  // 包含已删除
User::onlyTrashed()->find(1);  // 仅已删除
```

---

## 获取器/修改器

```php
class User extends Model
{
    // 获取器
    public function getStatusTextAttribute(): string
    {
        return $this->status === 1 ? '启用' : '禁用';
    }

    // 修改器
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

// 使用
$user->status_text; // 获取器
$user->password = '123456'; // 修改器自动加密
```

---

## 关联关系

```php
class User extends Model
{
    // 一对一（正向）
    public function profile() {
        return $this->hasOne(Profile::class);
    }

    // 一对多
    public function posts() {
        return $this->hasMany(Post::class);
    }

    // 属于（反向一对一）
    public function role() {
        return $this->belongsTo(Role::class);
    }

    // 多对多
    public function roles() {
        return $this->belongsToMany(Role::class);
    }

    // 多态
    public function comments() {
        return $this->morphMany(Comment::class, 'commentable');
    }

    public function commentable() {
        return $this->morphTo();
    }
}

// 使用
$user = User::find(1);
$profile = $user->profile;
$posts = $user->posts;

// 多对多操作
$user->roles()->attach($roleId);
$user->roles()->detach($roleId);
$user->roles()->sync([1, 2, 3]);
$user->roles()->toggle([1, 2]);
```

---

## Schema 表结构

```php
use Kode\Database\Schema\Schema;

// 创建表
Schema::create('users', function (Schema $table) {
    $table->id();
    $table->string('name');
    $table->string('email')->unique();
    $table->string('password');
    $table->integer('age');
    $table->boolean('is_active')->default(true);
    $table->decimal('balance', 10, 2)->default(0);
    $table->text('bio')->nullable();
    $table->json('options')->nullable();
    $table->timestamps();
    $table->softDeletes();

    // 索引
    $table->index(['user_id', 'created_at']);
    $table->uniqueKey('email');

    // 外键
    $table->foreign('user_id')
        ->references('id')
        ->on('users')
        ->onDelete('cascade');
});

// 修改表
Schema::table('users', function (Schema $table) {
    $table->string('phone', 20)->nullable();
    $table->index('phone');
});

// 删除表
Schema::drop('users');
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
$lastSql = $listener->getLastSql();
$listener->clear();
```

### 事务事件

```php
use Kode\Database\Event\EventManager;
use Kode\Database\Event\TransactionBeginEvent;
use Kode\Database\Event\TransactionCommitEvent;

EventManager::getInstance()->listen(TransactionBeginEvent::class, function ($event) {
    echo "事务开始\n";
});

EventManager::getInstance()->listen(TransactionCommitEvent::class, function ($event) {
    echo "事务提交\n";
});
```

---

## 连接池管理

```php
use Kode\Database\Pool\PoolManager;

PoolManager::init($config, 'default');
$connection = PoolManager::getConnection();

// 获取连接统计
$stats = PoolManager::getPool()->getStats();
// ['total' => 10, 'available' => 8, 'in_use' => 2]

// 清理过期连接
PoolManager::getPool()->cleanup();

// 重置连接池
PoolManager::getPool()->reset();
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

### 并行查询

```php
use Kode\Parallel\Parallel;

$parallel = new Parallel();
$results = $parallel->wait([
    fn() => Db::table('users')->get(),
    fn() => Db::table('orders')->get(),
]);
```

---

## 项目结构

```
src/
├── Connection/      # 数据库连接器
├── Db/             # 静态代理类
├── Event/          # 事件系统
│   ├── EventManager.php
│   ├── SqlEvent.php
│   ├── SqlListener.php
│   ├── SqlLogListener.php
│   └── Transaction*.php
├── Exception/      # 异常类
├── Model/          # 模型基类
│   ├── Concerns/   # Traits
│   │   ├── HasAttributes.php    # 获取器/修改器
│   │   ├── SoftDeletes.php       # 软删除
│   │   ├── Timestamps.php        # 时间戳
│   │   └── QueriesRelationships.php  # 关联查询
│   ├── Relation/   # 关联类
│   │   ├── HasOne.php
│   │   ├── HasMany.php
│   │   ├── BelongsTo.php
│   │   ├── BelongsToMany.php
│   │   ├── MorphOne.php
│   │   ├── MorphMany.php
│   │   ├── MorphTo.php
│   │   └── MorphToMany.php
│   ├── ModelEvent.php    # 模型事件
│   └── Observer.php       # 观察者
├── Pool/           # 连接池
│   ├── PoolInterface.php
│   ├── PoolManager.php
│   └── ConnectionPool.php
├── Query/          # 查询构建器
└── Schema/         # 表结构
```

---

## License

Apache-2.0
