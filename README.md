# Kode\Database

轻量级数据库适配器，兼容 Laravel、ThinkPHP、Hyperf ORM，支持 **多进程、多线程、协程** 环境下的数据库操作。

## 特性

- **双风格支持**：Laravel 风格 + ThinkPHP 风格
- **全 ORM 支持**：一对一、一对多、多对多、多态关联
- **连接池管理**：协程上下文隔离，支持 Fiber
- **事件监听**：SQL 监听、事务事件
- **Schema 定义**：表结构构建器
- **获取器/修改器**：类型转换、属性访问
- **软删除**：软删除恢复机制

## 环境要求

- PHP >= 8.1
- 兼容 Swoole、Workerman、RoadRunner 等常驻内存环境

## 安装

```bash
composer require kode/database
```

## 配置

```php
use Kode\Database\Db\Db;

Db::setConfig([
    'driver' => 'laravel',  // 或 'thinkphp'
    'host' => '127.0.0.1',
    'port' => 3306,
    'database' => 'test',
    'username' => 'root',
    'password' => '',
    'pool' => ['max' => 10, 'min' => 2]  // 连接池配置
]);
```

---

## 查询构建器用法

### Laravel 风格

```php
use Kode\Database\Db\Db;

// 获取所有
Db::table('users')->get();

// 获取第一条
Db::table('users')->first();

// 通过主键查找
Db::table('users')->find(1);

// 选择列 + 获取
Db::table('users')->select('name', 'email')->get();
Db::table('users')->select('name, email')->get();  // 逗号分隔字符串

// where 条件
Db::table('users')->where('status', '=', 1)->get();
Db::table('users')->where('status', 1)->get();      // 简写
Db::table('users')->whereIn('id', [1, 2, 3])->get();
Db::table('users')->whereNull('deleted_at')->get();

// 排序分页
Db::table('users')->orderBy('id', 'DESC')->limit(10)->get();
Db::table('users')->offset(10)->limit(10)->get();

// 聚合
Db::table('users')->count();
Db::table('users')->sum('balance');

// 插入
Db::table('users')->insert(['name' => 'test']);
Db::table('users')->insertAll([['name' => 'a'], ['name' => 'b']]);

// 更新
Db::table('users')->where('id', '=', 1)->update(['name' => 'newname']);

// 删除
Db::table('users')->where('id', '=', 1)->delete();

// 分页
Db::table('users')->paginate(1, 15);
```

### ThinkPHP 风格

```php
use Kode\Database\Db\Db;

// 获取所有
Db::table('users')->select();

// 获取第一条
Db::table('users')->find();

// 通过主键查找
Db::table('users')->find(1);

// 选择列
Db::table('users')->field('name, email')->select();
Db::table('users')->field(['name', 'email'])->select();

// where 条件
Db::table('users')->where('status', '=', 1)->select();
Db::table('users')->where('status', 1)->select();
Db::table('users')->whereIn('id', [1, 2, 3])->select();
Db::table('users')->whereNull('deleted_at')->select();

// 排序分页
Db::table('users')->order('id DESC')->limit(10)->select();
Db::table('users')->page(1, 15)->select();

// 聚合
Db::table('users')->count();
Db::table('users')->sum('balance');

// 插入
Db::table('users')->insert(['name' => 'test']);
Db::table('users')->insertAll([['name' => 'a'], ['name' => 'b']]);

// 更新
Db::table('users')->where('id', '=', 1)->update(['name' => 'newname']);

// 删除
Db::table('users')->where('id', '=', 1)->delete();

// 分页
Db::table('users')->paginate(15);
```

---

## 原生 SQL

```php
// 查询
Db::select('SELECT * FROM users WHERE id = ?', [1]);

// 插入
Db::insert('INSERT INTO users (name) VALUES (?)', ['test']);

// 更新
Db::update('UPDATE users SET name = ? WHERE id = ?', ['test', 1]);

// 删除
Db::delete('DELETE FROM users WHERE id = ?', [1]);

// 语句
Db::statement('DROP TABLE IF EXISTS users');
```

---

## 事务

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

```php
use Kode\Database\Model\Model;

class User extends Model
{
    protected string $table = 'users';
    protected string $primaryKey = 'id';
    protected array $fillable = ['name', 'email', 'status'];
    protected array $guarded = ['password'];
    protected bool $timestamps = true;
}
```

### 增删改查

```php
// 查找
User::find(1);           // 通过主键
User::findOrFail(1);     // 不存在则抛异常
User::first();           // 第一条
User::all();             // 所有

// 创建
User::create(['name' => 'test', 'email' => 'test@example.com']);

// 更新
$user = User::find(1);
$user->name = 'newname';
$user->save();

// 删除
$user->delete();
$user->forceDelete();   // 硬删除

// 查找或创建
User::firstOrCreate(['email' => 'test@test.com'], ['name' => 'new']);
User::updateOrCreate(['email' => 'test@test.com'], ['name' => 'updated']);
```

### 查询构建

```php
User::query()->where('status', '=', 1)->get();
User::query()->whereIn('id', [1, 2, 3])->get();
User::query()->orderBy('id', 'DESC')->limit(10)->get();
User::query()->paginate(1, 15);

// 聚合
User::count();
User::sum('balance');
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

    // 多对多
    public function roles() {
        return $this->belongsToMany(Role::class);
    }
}

// 使用
$user = User::find(1);
$profile = $user->profile;
$posts = $user->posts;
$user->roles()->attach($roleId);
```

### 多态关联

```php
class Comment extends Model
{
    public function commentable() {
        return $this->morphTo();
    }
}

class Post extends Model
{
    public function comments() {
        return $this->morphMany(Comment::class, 'commentable');
    }
}
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
    ];
}
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
$user->restore();     // 恢复
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
    $table->timestamps();
    $table->softDeletes();
});

// 修改表
Schema::table('users', function (Schema $table) {
    $table->string('phone', 20);
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

// 注册 SQL 监听器
$listener = new SqlListener();
EventManager::getInstance()->listen(SqlEvent::class, $listener);

// 执行查询
Db::table('users')->get();

// 获取监听的 SQL
$sqls = $listener->getSqls();
$lastSql = $listener->getLastSql();
$listener->clear();
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

### 进程池

```php
use Kode\Process\Pool;

$pool = Pool::create(fn() => new \Kode\Database\Pool\ConnectionPool($config));
$results = $pool->map(fn($worker) => $worker->query('SELECT 1'));
```

---

## 连接池管理

```php
use Kode\Database\Pool\PoolManager;

PoolManager::init($config, 'default');
$connection = PoolManager::getConnection();

// Fiber 中自动隔离
if (class_exists(\Fiber::class)) {
    $fiberId = \Fiber::getCurrent()->getId();
}
```

---

## 项目结构

```
src/
├── Connection/      # 数据库连接器
├── Db/             # 静态代理类
├── Event/          # 事件系统
├── Exception/      # 异常类
├── Model/          # 模型基类
│   ├── Concerns/   # Traits
│   └── Relation/   # 关联类
├── Pool/           # 连接池
├── Query/          # 查询构建器
└── Schema/         # 表结构
```

---

## License

Apache-2.0
