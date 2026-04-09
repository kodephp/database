# Kode\Database

轻量级数据库适配器，兼容 Laravel、ThinkPHP、Hyperf ORM，支持 **多进程、多线程、协程** 环境下的数据库操作。

## 特性

- **全 ORM 支持**：一对一、一对多、多对多、多态关联
- **连接池管理**：协程上下文隔离，支持 Fiber
- **事件监听**：SQL 监听、事务事件
- **Schema 定义**：表结构构建器
- **获取器/修改器**：类型转换、属性访问
- **软删除**：软删除恢复机制
- **兼容主流框架**：Laravel、ThinkPHP、Hyperf、Webman

## 环境要求

- PHP >= 8.1
- 兼容 Swoole、Workerman、RoadRunner 等常驻内存环境

## 安装

```bash
composer require kode/database
```

## 快速开始

### 配置

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

### Db 静态调用（Webman 风格）

```php
use Kode\Database\Db\Db;

// 查询构建
Db::table('users')->where('status', '=', 1)->orderBy('id', 'DESC')->limit(10)->get();
Db::table('users')->select()->first();

// 原生 SQL
Db::select('SELECT * FROM users WHERE id = ?', [1]);
Db::insert('INSERT INTO users (name) VALUES (?)', ['test']);
Db::update('UPDATE users SET name = ? WHERE id = ?', ['test', 1]);
Db::delete('DELETE FROM users WHERE id = ?', [1]);

// 事务
Db::transaction(function () {
    Db::table('users')->insert(['name' => 'test']);
    Db::table('accounts')->insert(['user_id' => 1, 'balance' => 100]);
});
```

### Model 模型（Hyperf/Laravel 风格）

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
}

// 查找
$user = User::find(1);
$user = User::findOrFail(1);
$user = User::first();

// 创建
$user = User::create(['name' => 'test', 'email' => 'test@example.com']);

// 更新
$user = User::find(1);
$user->name = 'newname';
$user->save();

// 删除
$user->delete();
$user->forceDelete();

// 查找或创建
User::firstOrCreate(['email' => 'test@test.com'], ['name' => 'new']);
```

## 关联关系

### 一对一（正向）

```php
class User extends Model
{
    public function profile(): \Kode\Database\Model\Relation\HasOne
    {
        return $this->hasOne(Profile::class);
    }
}

$user = User::find(1);
$profile = $user->profile;
```

### 一对多

```php
class User extends Model
{
    public function posts(): \Kode\Database\Model\Relation\HasMany
    {
        return $this->hasMany(Post::class);
    }
}

$user = User::find(1);
$posts = $user->posts;
```

### 属于（反向一对一）

```php
class Profile extends Model
{
    public function user(): \Kode\Database\Model\Relation\BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
```

### 多对多

```php
class User extends Model
{
    public function roles(): \Kode\Database\Model\Relation\BelongsToMany
    {
        return $this->belongsToMany(Role::class);
    }
}

// 关联操作
$user->roles()->attach($roleId);
$user->roles()->detach($roleId);
$user->roles()->sync([1, 2, 3]);
$user->roles()->toggle([1, 2]);
```

### 多态关联

```php
class Comment extends Model
{
    public function commentable(): \Kode\Database\Model\Relation\MorphTo
    {
        return $this->morphTo();
    }
}

class Post extends Model
{
    public function comments(): \Kode\Database\Model\Relation\MorphMany
    {
        return $this->morphMany(Comment::class, 'commentable');
    }
}
```

## 查询构建

```php
// where 条件
User::where('status', '=', 1)->get();
User::where('status', 1)->get();
User::whereIn('id', [1, 2, 3])->get();
User::whereNull('deleted_at')->get();

// 聚合函数
User::count();
User::sum('balance');
User::avg('balance');
User::max('balance');
User::min('balance');

// 分页
User::paginate(1, 15);
```

## 获取器/修改器

```php
class User extends Model
{
    // 获取器
    public function getStatusTextAttribute(): string
    {
        return match ($this->status) {
            1 => '启用',
            0 => '禁用',
            default => '未知',
        };
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
    ];
}
```

## 软删除

```php
class User extends Model
{
    use \Kode\Database\Model\Concerns\SoftDeletes;

    protected string $softDeleteField = 'deleted_at';
}

$user->delete();       // 软删除
$user->forceDelete();   // 硬删除
$user->restore();       // 恢复
```

## Schema 表结构

```php
use Kode\Database\Schema\Schema;

$sql = Schema::create('users', function (Schema $table) {
    $table->id();
    $table->string('name');
    $table->string('email')->unique();
    $table->string('password');
    $table->integer('age');
    $table->boolean('is_active')->default(true);
    $table->decimal('balance', 10, 2)->default(0);
    $table->text('bio');
    $table->json('options');
    $table->timestamps();
    $table->softDeletes();

    // 索引
    $table->index(['user_id', 'created_at']);

    // 外键
    $table->foreign('user_id')
        ->references('id')
        ->on('users')
        ->onDelete('cascade');
});
```

## 事件监听

### SQL 监听

```php
use Kode\Database\Event\EventManager;
use Kode\Database\Event\SqlListener;
use Kode\Database\Event\SqlEvent;

$listener = new SqlListener();
EventManager::getInstance()->listen(SqlEvent::class, $listener);

Db::table('users')->select()->get();

$sqls = $listener->getSqls();
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

## 多进程/多线程/协程支持

### Fiber 协程

```php
$fiber = new Fiber(function () {
    Db::setConfig([...]);
    return Db::table('users')->where('status', 1)->select()->get();
});

$result = $fiber->start();
```

### 并行查询

```php
use Kode\Parallel\Parallel;

$parallel = new Parallel();
$results = $parallel->wait([
    fn() => Db::table('users')->select()->get(),
    fn() => Db::table('orders')->select()->get(),
]);
```

### 进程池

```php
use Kode\Process\Pool;

$pool = Pool::create(function () {
    return new \Kode\Database\Pool\ConnectionPool($config);
});

$results = $pool->map(fn($worker) => $worker->query('SELECT 1'));
```

## 连接池管理

```php
use Kode\Database\Pool\PoolManager;

PoolManager::init($config, 'default');

$connection = PoolManager::getConnection();

// 在 Fiber 中自动隔离
if (class_exists(\Fiber::class)) {
    $fiberId = \Fiber::getCurrent()->getId();
    // 每个 Fiber 有独立连接
}
```

## Kode 系列包集成

| 包名 | 用途 |
|------|------|
| `kode/di` | 依赖注入 |
| `kode/context` | 协程/进程上下文传递 |
| `kode/fibers` | Fiber 协程支持 |
| `kode/parallel` | 并行查询 |
| `kode/process` | 进程池管理 |
| `kode/cache` | 查询结果缓存 |
| `kode/event` | 事件监听 |
| `kode/exception` | 统一异常处理 |

## 项目结构

```
src/
├── Connection/      # 数据库连接器
├── Db/             # 静态代理类
├── Event/          # 事件系统
├── Exception/      # 异常类
├── Model/          # 模型基类
│   ├── Concerns/  # Traits
│   └── Relation/   # 关联类
├── Pool/           # 连接池
├── Query/          # 查询构建器
└── Schema/         # 表结构
```

## License

Apache-2.0
