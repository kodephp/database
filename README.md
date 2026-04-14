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

// 日期时间查询
Db::table('orders')->whereDate('created_at', '=', '2024-01-01')->get();
Db::table('orders')->whereYear('created_at', '=', '2024')->get();
Db::table('orders')->whereMonth('created_at', '=', '01')->get();
Db::table('orders')->whereDay('created_at', '=', '01')->get();
Db::table('orders')->whereTime('created_at', '>=', '09:00:00')->get();

// Null 查询
Db::table('users')->whereNull('deleted_at')->get();
Db::table('users')->whereNotNull('email')->get();

// Between 查询
Db::table('users')->whereBetween('age', 18, 30)->get();
Db::table('users')->whereNotBetween('age', 18, 30)->get();

// 列比较
Db::table('orders')->whereColumn('updated_at', '>', 'created_at')->get();

// 原始条件
Db::table('users')->whereRaw('status = ? AND age > ?', [1, 18])->get();

// 子查询
Db::table('users')
    ->where('id', 'in', function($q) {
        $q->select('user_id')->from('orders')->where('total', '>', 100);
    })
    ->get();

Db::table('users')->whereExists(function($q) {
    $q->select('*')->from('orders')->whereRaw('orders.user_id = users.id');
})->get();

// 联合查询
Db::table('users')->where('status', 1)
    ->union('SELECT * FROM admins')
    ->get();

Db::table('users')
    ->unionAll(Db::table('temp_users'))
    ->get();

// 复制查询
$query = Db::table('users')->where('status', 1);
$clonedQuery = $query->copy();
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

// 便捷方法
Db::table('users')->limitBy(10)->get();        // 限制数量
Db::table('users')->take(10)->get();           // 取前 N 条（limit 别名）
Db::table('users')->skip(10)->take(10)->get(); // 跳过 N 条
Db::table('users')->pluck('name');             // 获取单列值列表
Db::table('users')->lists('id', 'name');       // 获取键值对

// 调试方法
Db::table('users')->where('id', 1)->dump();   // 打印 SQL 和 bindings
Db::table('users')->where('id', 1)->dd();      // 打印并终止

// 便捷查询方法
Db::table('users')->isEmpty();           // 检查是否为空
Db::table('users')->isNotEmpty();        // 检查是否不为空
Db::table('users')->findOrCreate(['email' => 'test@example.com'], ['name' => 'test']); // 查找或创建
Db::table('users')->batchUpdateBy([['id' => 1, 'name' => 'a'], ['id' => 2, 'name' => 'b']], 'id'); // 批量更新
Db::table('users')->batchDeleteBy([1, 2, 3], 'id'); // 批量删除
Db::table('users')->findOrFail(['id' => 1]); // 查找或抛出异常
Db::table('users')->firstOrFail(); // 获取第一条或抛出异常
Db::table('users')->chunkById(100, function ($users, $lastId) { /* 处理每批 */ }); // 按主键分块
Db::table('users')->existsBy(fn($q) => $q->where('status', 1)); // 条件是否存在
Db::table('users')->begin(); // 获取第一条
Db::table('users')->end(); // 获取最后一条
Db::table('users')->nth(5); // 获取第 N 条
Db::table('users')->random(3); // 获取随机记录
Db::table('users')->hasConditions(); // 检查是否有查询条件
Db::table('users')->whereCount(); // 获取条件数量
Db::table('users')->clearWhere(); // 清空 WHERE 条件
Db::table('users')->clearOrderBy(); // 清空排序
Db::table('users')->clearLimit(); // 清空 limit/offset
Db::table('users')->reset(); // 重置所有条件
Db::table('users')->toInfo(); // 获取查询信息摘要
Db::table('users')->copy(); // 复制查询构建器
Db::table('users')->notExists(); // 检查记录是否不存在
Db::table('users')->countBy('status'); // 统计字段值出现次数
Db::table('users')->tap(function($q) { /* ... */ }); // 检验查询条件
Db::table('users')->withScope(function($q) { /* ... */ }); // 添加全局作用域
```

### 聚合统计

```php
// 聚合方法
Db::table('users')->count();          // 统计数量
Db::table('users')->min('age');       // 获取最小值
Db::table('users')->max('age');       // 获取最大值
Db::table('users')->avg('age');       // 获取平均值
Db::table('users')->sum('age');       // 获取总和
Db::table('users')->countBy('status'); // 统计字段值出现次数
Db::table('users')->countWithConditions(); // 带条件的记录数
```

### 查询分析

```php
// 查询分析
Db::table('users')->where('status', 1)->explain();      // 执行 EXPLAIN
Db::table('users')->where('status', 1)->explainInfo(); // 获取分析摘要
Db::table('users')->where('status', 1)->toLookSql();  // 生成可读 SQL（用于调试）
Db::table('users')->hasLimit();     // 检查是否设置 limit
Db::table('users')->hasOffset();    // 检查是否设置 offset
Db::table('users')->getLimit();     // 获取 limit 值
Db::table('users')->getOffset();   // 获取 offset 值
Db::table('users')->getOrderBy();   // 获取排序列
Db::table('users')->getOrderDirection(); // 获取排序方向
Db::table('users')->isSafe();       // 验证 SQL 安全性
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

### Connection 连接类

```php
// 获取连接
$conn = Db::connection('default');

// 连接操作
$conn->isConnected();           // 检查连接是否正常
$conn->ping();                 // Ping 数据库检查连接
$conn->reconnect();            // 重新连接
$conn->getVersion();           // 获取数据库版本（驱动自适应）
$conn->getCurrentDatabase();   // 获取当前数据库名（驱动自适应）
$conn->getTables();            // 获取所有表名
$conn->tableExists('users');   // 检查表是否存在（驱动自适应）
$conn->getTableColumns('users'); // 获取表的所有字段（驱动自适应）
$conn->getPrimaryKey('users');  // 获取表的主键字段
$conn->getIndexes('users');     // 获取表的索引信息
$conn->getLastInsertId();       // 获取最后插入 ID（驱动自适应）
$conn->buildPaginationSql($sql, 10, 0); // 构建分页 SQL（驱动自适应）
```

### 多数据库驱动支持

```php
// 支持的驱动类型：mysql, pgsql, sqlite, sqlsrv, oracle

// 获取当前驱动类型
$conn = Db::connection('default');
$driver = $conn->getDriver();  // 'mysql'

// 检查是否为指定驱动
$conn->isDriver('mysql');     // true
$conn->isDriver('pgsql');     // false

// 检查是否支持驱动
Connection::isSupportedDriver('mysql');  // true
Connection::isSupportedDriver('pgsql'); // true
Connection::isSupportedDriver('sqlite'); // true

// 获取驱动特定方法
$conn->getDriverMethod('lastInsertId'); // 'LAST_INSERT_ID()' (MySQL)
$conn->getDriverMethod('now');          // 'NOW()' (MySQL)

// 驱动特定 SQL 差异自动处理
// - MySQL: LIMIT x OFFSET y
// - PostgreSQL: LIMIT x OFFSET y (支持 RETURNING)
// - SQLite: LIMIT x OFFSET y
// - SQL Server: OFFSET x ROWS FETCH NEXT y ROWS ONLY

// PostgreSQL RETURNING 子句支持
// $conn->getDriverMethod('returning'); // 'RETURNING'
```

### 增删改

```php
// 插入
Db::table('users')->insert(['name' => 'test', 'email' => 'test@example.com']);
Db::table('users')->insertAll([
    ['name' => 'a', 'email' => 'a@example.com'],
    ['name' => 'b', 'email' => 'b@example.com']
]);
Db::table('users')->insertBatch([
    ['name' => 'a', 'email' => 'a@example.com'],
    ['name' => 'b', 'email' => 'b@example.com'],
    ['name' => 'c', 'email' => 'c@example.com'],
]); // 批量插入（多行 INSERT）

// 更新
Db::table('users')->where('id', '=', 1)->update(['name' => 'newname']);
Db::table('users')->updateBatch(['name' => 'newname'], ['id' => 1]); // 批量更新

// UPSERT（插入或更新）
Db::table('users')->upsert(['id' => 1, 'name' => 'newname'], ['id']); // 单条
Db::table('users')->upsertBatch([
    ['id' => 1, 'name' => 'a'],
    ['id' => 2, 'name' => 'b'],
], ['id']); // 批量 UPSERT

// 自增自减
Db::table('users')->where('id', '=', 1)->inc('views');
Db::table('users')->where('id', '=', 1)->inc('score', 10);
Db::table('users')->where('id', '=', 1)->dec('balance', 100);

// 删除
Db::table('users')->where('id', '=', 1)->delete();
Db::table('users')->deleteBatch([1, 2, 3]); // 批量删除

// 查找或创建
Db::table('users')->firstOrCreate(['email' => 'test@example.com'], ['name' => 'test']);

// 更新或创建
Db::table('users')->updateOrCreate(['email' => 'test@example.com'], ['name' => 'test']);
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

// 插入或忽略（唯一键冲突时忽略）
Db::table('users')->insertOrIgnore(['email' => 'test@example.com', 'name' => 'test']);

// 条件更新
Db::table('users')->updateIf(['status' => 1], fn($q) => $q->where('id', '>', 10));

// 条件删除
Db::table('users')->deleteIf(fn($q) => $q->where('status', 0));

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

// 数据库工具方法
$tables = Db::getTables();           // 获取所有表名
$views = Db::getViews();              // 获取所有视图
$databases = Db::getDatabases();     // 获取所有数据库
$indexes = Db::getIndexes('users');  // 获取表索引
$foreignKeys = Db::getForeignKeys('orders'); // 获取外键

// 表维护
Db::rebuildTable('users');           // 重建表
Db::optimizeTable('users');          // 优化表
Db::analyzeTable('users');           // 分析表

// 表状态和大小
$status = Db::getTableStatus('users');  // 表状态信息
$size = Db::getTableSize('users');       // 表大小（字节）
$dbSize = Db::getDatabaseSize();        // 数据库大小（字节）
echo Db::formatBytes($size);             // 格式化大小 "10.5 MB"

// 表操作
Db::backupTable('users');                // 备份表结构
Db::copyTableStructure('users', 'users_backup'); // 复制表结构
Db::renameTable('old_table', 'new_table'); // 重命名表
Db::truncateTable('users');              // 清空表（自增归零）
Db::dropTable('users');                  // 删除表
Db::dropTables(['users', 'orders']);     // 删除多个表

// 表信息查询
Db::hasTable('users');                  // 检查表是否存在
Db::hasColumn('users', 'email');        // 检查字段是否存在
$tables = Db::tables();                 // 获取所有表名
$columns = Db::columns('users');       // 获取表字段列表
$indexes = Db::indexes('users');        // 获取表索引信息
$primaryKeys = Db::primaryKeys('users'); // 获取主键字段
$version = Db::getVersion();            // 获取数据库版本

// 执行 SQL 文件
$results = Db::executeFile('/path/to.sql');

// 关闭所有连接
Db::closeAllConnections();

// 查询日志
Db::enableQueryLog(true);  // 启用查询日志
$logs = Db::getQueryLog(); // 获取所有查询日志
Db::clearQueryLog();       // 清除查询日志
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

// Model 工具方法
$info = User::getInfo();                    // 获取模型元信息
$table = User::getTableName();              // 获取表名
$pk = User::getPrimaryKeyName();            // 获取主键名
$fillable = User::getFillableFields();     // 获取 fillable 字段
$guarded = User::getGuardedFields();       // 获取 guarded 字段
$isFillable = User::checkFillable('name'); // 检查字段是否可以批量赋值
$hasSoftDeletes = User::hasSoftDeletes();   // 检查是否使用软删除
$total = User::count();                     // 获取记录数
$activeCount = User::countWhere(['status' => 1]); // 带条件统计
$lastId = User::getLastInsertId();         // 获取最后插入 ID
$dbName = User::getDatabaseName();         // 获取数据库名称
$statusStats = User::groupByStatus();     // 统计各状态数量
$rawResult = User::raw('SELECT * FROM users LIMIT 1'); // 执行原生 SQL

// Model 实例工具方法
$user = User::find(1);
$shardingCount = $user->getShardingCount();     // 获取分片数量
$shardingStrategy = $user->getShardingStrategy(); // 获取分片策略
$connectionName = $user->getConnectionName();     // 获取连接名称

// Model 便捷静态方法
$exists = User::checkExists(['email' => 'test@example.com']); // 检查记录是否存在
$record = User::one(['id' => 1]);                // 获取单条记录
$record = User::findOrCreate(['email' => 'new@example.com'], ['name' => 'New']); // 查找或创建
User::updateOrCreate(['email' => 'test@example.com'], ['name' => 'Updated']); // 更新或创建
$records = User::findBy([1, 2, 3], 'id');       // 批量查找
$count = User::countBy(['status' => 1]);       // 条件计数
$hasRecord = User::has(['email' => 'test@example.com']); // 判断是否存在
$className = User::getClassName();              // 获取模型类名
$shortClassName = User::getShortClassName();     // 获取模型简短类名
$prefix = User::getTablePrefix();              // 获取表前缀
$tableWithoutPrefix = User::getTableNameWithoutPrefix(); // 获取不含前缀的表名
$values = User::values('name', ['status' => 1]); // 获取单个字段值列表
$paginator = User::page(1, 15, ['status' => 1]); // 分页查询
$first = User::first();                         // 获取第一条记录
$last = User::last();                           // 获取最后一条记录
$nth = User::nth(5);                            // 获取第 N 条记录
$existsById = User::existsById(1);             // 根据 ID 检查是否存在
$existingIds = User::existsByIds([1, 2, 3]);  // 批量检查 ID 是否存在

// SQL 日志功能
User::enableSqlLog();                           // 启用 SQL 日志
User::disableSqlLog();                          // 禁用 SQL 日志
$logs = User::getSqlLog();                      // 获取 SQL 日志
$lastSql = User::getLastSql();                  // 获取最后执行的 SQL
User::clearSqlLog();                            // 清除 SQL 日志

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
use Kode\Database\Model\ObserverManager;

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

// 注册观察者（方式一：模型注册）
User::observe(UserObserver::class);

// 方式二：使用 ObserverManager 批量管理
ObserverManager::register(User::class, UserObserver::class);
ObserverManager::register(Order::class, OrderObserver::class);

// 检查观察者是否存在
ObserverManager::has(User::class);  // true

// 获取观察者
$observer = ObserverManager::get(User::class);

// 触发观察者事件（手动）
ObserverManager::fire(User::class, 'created', $user);

// 全局观察者（所有模型都会触发）
ObserverManager::registerGlobal(function($model, $event) {
    Log::info("模型事件: {$event}");
}, '*');  // '*' 表示所有事件

ObserverManager::registerGlobal(function($model, $event) {
    // 只监听 created 事件
    if ($event === 'created') {
        Cache::clear('user_list');
    }
}, 'created');

// 清除观察者
ObserverManager::unregister(User::class);
ObserverManager::clear();  // 清除所有
```

### 模型事件

```php
// 方式一：闭包注册
User::creating(function ($user) {
    $user->password = password_hash($user->password, PASSWORD_DEFAULT);
});

User::created(function ($user) {
    Log::info("用户创建成功: {$user->id}");
});

// 方式二：观察者类
class UserObserver
{
    public function creating(Model $user): void
    {
        $user->password = password_hash($user->password, PASSWORD_DEFAULT);
    }

    public function created(Model $user): void
    {
        Log::info("用户创建成功: {$user->id}");
    }
}

// 注册
User::observe(UserObserver::class);

// 支持的事件
// creating/created - 创建前/后
// updating/updated - 更新前/后
// saving/saved - 保存前/后（插入或更新）
// deleting/deleted - 删除前/后
// restoring/restored - 恢复前/后（软删除）
// forceDeleting/forceDeleted - 强制删除前/后

### 一次性事件

```php
// 注册一次性事件（执行后自动清除）
User::once('creating', function ($user) {
    $user->temp_field = '临时值';  // 只在第一次创建时生效
});

// 获取所有一次性事件
User::getOnceEvents();  // ['creating']

// 清除一次性事件
User::clearOnceEvents();  // 清除所有
User::clearOnceEvents('creating');  // 清除指定
```

### 队列事件

```php
// 注册队列事件（需要队列处理器）
User::queue('created', function ($user) {
    // 发送欢迎邮件等异步操作
});

// 获取所有队列事件
User::getQueuedEvents();  // ['created']

// 清除队列事件
User::clearQueuedEvents();  // 清除所有
```

### 事件优先级

```php
// 设置事件优先级（数字越大优先级越高）
User::setPriority('creating', 100);
User::setPriority('saving', 50);

// 获取已注册的事件
User::getRegisteredEvents();  // ['creating', 'saving', ...]

// 检查事件是否已注册
User::hasEvent('creating');  // true

// 批量注册事件
User::registerEvents([
    'creating' => function ($user) { /* ... */ },
    'created' => function ($user) { /* ... */ },
]);

// 清除事件
User::clearEvent('creating');  // 清除单个
User::clearAllEvent();  // 清除所有
User::clearAllEvents();  // 清除所有事件（含观察者、一次性、队列）
```

### Connection 查询钩子

```php
use Kode\Database\Db\Connection;

// 注册查询前钩子（所有连接）
Connection::beforeQuery(function ($sql, $bindings, $conn) {
    Log::debug("SQL: {$sql}", $bindings);
});

// 注册查询前钩子（指定连接）
Connection::beforeQuery(function ($sql, $bindings, $conn) {
    Log::debug("Slave SQL: {$sql}");
}, 'slave');

// 注册查询后钩子
Connection::afterQuery(function ($sql, $bindings, $result, $conn) {
    Log::info("查询完成: {$sql}", ['rows' => count($result)]);
});

// 同时注册前后钩子
Connection::registerQueryHook([
    'before' => function ($sql, $bindings, $conn) {
        // 查询前
    },
    'after' => function ($sql, $bindings, $result, $conn) {
        // 查询后
    }
], 'default');

// 获取钩子
Connection::getBeforeQueryHooks();
Connection::getAfterQueryHooks();

// 清除钩子
Connection::clearQueryHooks();  // 清除所有
Connection::clearQueryHooks('slave');  // 清除指定
```

---

## 软删除

```php
class User extends Model
{
    use \Kode\Database\Model\Concerns\SoftDeletes;
    protected string $softDeleteField = 'deleted_at';
}

// 模型实例操作
$user = User::find(1);
$user->delete();       // 软删除（设置 deleted_at 时间戳）
$user->forceDelete();  // 硬删除（直接从数据库删除）
$user->restore();      // 恢复软删除（设置 deleted_at 为 null）

// 静态方法操作
User::destroy([1, 2, 3]);           // 批量软删除
User::forceDeleteBatch([1, 2, 3]);  // 批量硬删除
User::deleteBatch([1, 2, 3]);       // 批量软删除（别名）

// 设置软删除字段
$user->setSoftDeleteField('deleted_at'); // 设置软删除字段名
$user->getSoftDeleteField();             // 获取软删除字段名

// 检查是否使用软删除
$user->usesSoftDeletes(); // 检查模型是否使用软删除

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

### 模型序列化

```php
// 转换为数组
$user = User::find(1);
$array = $user->toArray();

// 转换为 JSON
$json = $user->toJson();

// 隐藏字段
class User extends Model
{
    protected array $hidden = ['password', 'remember_token'];
}

// 只显示指定字段
$user->makeVisible(['password']);
$array = $user->toArray();

// 追加字段
$user->append(['status_text']);
$array = $user->toArray();

// 检查修改
$user->isDirty();              // 是否有修改
$user->isDirty('name');       // 指定字段是否修改
$user->wasChanged('name');    // 指定字段是否被修改过
$dirty = $user->getDirty();   // 获取修改的字段

// 原始数据
$original = $user->getOriginal();           // 获取所有原始数据
$name = $user->getOriginalValue('name');   // 获取指定原始值

// 同步原始数据
$user->syncOriginal();        // 同步所有原始数据
$user->syncChanges();         // 同步修改的数据

// 属性操作
$user->hasAttribute('name');           // 检查属性是否存在
$keys = $user->getAttributeKeys();     // 获取所有属性名
$user->getAttributesOnly(['id', 'name']); // 只获取指定属性
$user->except(['password']);           // 排除指定属性
$user->only(['id', 'name']);          // 只获取指定属性
$user->merge(['name' => 'new']);       // 合并属性
$user->forceSetAttribute('id', 100);   // 强制设置属性（绕过修改器）
$value = $user->forceGetAttribute('id'); // 强制获取属性（绕过获取器）
$user->clearRelations();                // 清空关联
$debug = $user->debug();               // 获取调试信息

// 模型状态判断
$user->refresh();                     // 刷新模型（从数据库重新加载）
$user->isDirty();                     // 检查是否有未保存的更改
$user->isClean();                     // 检查是否干净（没有未保存的更改）
$user->markAsClean();                 // 标记为已保存
$dirty = $user->getDirty();           // 获取已修改的属性
$user->is($anotherUser);              // 检查两个模型是否相同

// 模型转换
$array = $user->toArray();           // 转换为数组
$json = $user->toJson();              // 转换为 JSON
$raw = $user->getRawAttr();          // 获取原始属性
$original = $user->getRawOriginal();  // 获取原始数据

// 模型比较
$diff = $user->diff($anotherUser);    // 比较模型差异
$age = $user->age();                  // 获取模型年龄（基于 created_at）
$age = $user->age('updated_at');     // 指定时间字段

// 模型工具方法
$clone = $user->replicate();        // 克隆模型（创建副本）
$hash = $user->hash();              // 获取模型哈希值
$hashId = $user->hashId();          // 获取模型 ID 哈希值
$hasAttr = $user->hasAttribute('name'); // 检查属性是否存在
$attrNames = $user->getAttributeNames(); // 获取所有属性名
$attrCount = $user->countAttributes(); // 获取属性数量
$changes = $user->getChanges();      // 获取修改的变化
$user->setData(['name' => 'new']);  // 批量设置属性
$withDefaults = $user->toArrayWithDefaults(); // 获取包含默认值的数组
$isDeleted = $user->isDeleted();     // 检查是否已删除
$isNew = $user->isNew();            // 检查是否是新模型
$summary = $user->summary();         // 获取模型摘要信息
$defaults = $user->getDefaults();    // 获取所有默认值
$user->setDefault('name', 'default'); // 设置默认值
$hasDefault = $user->hasDefault('name'); // 检查是否有默认值
$merged = $user->merge(['name' => 'new']); // 合并属性
$only = $user->only(['id', 'name']);  // 只获取指定属性
$except = $user->except(['password']); // 排除指定属性
$hasAttrs = $user->hasAttributes(['id', 'name']); // 检查多个属性
$first = $user->firstAttribute();    // 获取第一个属性
$last = $user->lastAttribute();       // 获取最后一个属性
$serialized = $user->serialize();     // 序列化模型
$user2 = User::unserialize($serialized); // 反序列化模型
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
    $table->binary('data');      // binary
    $table->enum('status', ['active', 'inactive', 'pending']); // enum

    // 字段修饰符
    $table->string('name')->default('Anonymous');  // 默认值
    $table->string('email')->nullable();            // 可为空
    $table->integer('votes')->unsigned();           // 无符号
    $table->string('remark')->comment('备注');      // 字段注释
    $table->string('phone')->after('email');       // 字段位置
    $table->primary();                             // 将上一个字段设为主键
    $table->primary(['id', 'code']);               // 复合主键

    // Schema 工具方法
    $table->getTable();      // 获取表名
    $columns = $table->getColumns();   // 获取所有字段
    $indexes = $table->getIndexes();   // 获取所有索引
    $foreignKeys = $table->getForeignKeys(); // 获取所有外键
    $engine = $table->getEngine();     // 获取存储引擎
    $charset = $table->getCharset();   // 获取字符集
    $collation = $table->getCollation(); // 获取排序规则
    $options = $table->getOptions();   // 获取表选项
    $table->columnExists('email');     // 检查字段是否存在
    $table->renameColumn('name', 'username'); // 重命名字段
    $table->dropIndex('idx_email');   // 删除索引
    $renameSql = $table->renameTableSql('new_users'); // 生成重命名表 SQL
    $truncateSql = $table->truncateTableSql(); // 生成清空表 SQL
    $dropSql = $table->dropTableSql(); // 生成删除表 SQL

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

### Connection 连接类

```php
use Kode\Database\Db\Connection;

// 创建连接实例
$conn = new Connection('mysql');  // 使用默认配置
$conn = new Connection('mysql', 'database_name'); // 指定数据库

// 获取查询构建器
$builder = $conn->query();

// 执行查询
$results = $conn->select('SELECT * FROM users WHERE status = ?', [1]);
$count = $conn->count('SELECT COUNT(*) FROM users');

// 执行 SQL
$conn->statement('INSERT INTO users (name) VALUES (?)', ['test']);

// 切换数据库
$conn->useDatabase('another_database');

// 获取表全名（带数据库前缀）
$fullTableName = $conn->qualify('users');  // `database_name`.`users`

// 获取数据库名
$dbName = $conn->getDatabaseName();  // database_name

// 克隆连接
$conn2 = $conn->copy();

// 原生查询
$results = $conn->raw('SELECT * FROM users', []);
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

// 初始化连接池（默认类型）
PoolManager::init($config, 'default');

// 初始化不同类型的连接池
PoolManager::init($config, 'default', 'connection');  // 普通连接池
PoolManager::init($config, 'default', 'process');    // 进程池
PoolManager::init($config, 'default', 'parallel');  // 并行池
PoolManager::init($config, 'default', 'fiber');      // 协程池

// 获取连接池
$pool = PoolManager::getPool();

// 获取连接统计
$stats = $pool->getStats();
print_r($stats);
// 输出: ['type' => 'connection', 'total' => 10, 'available' => 5, 'in_use' => 5, ...]

// 获取连接池类型
$type = PoolManager::getPoolType();  // 返回: connection|process|parallel|fiber

// 检查是否支持指定池类型
$supports = PoolManager::supports('fiber');  // true

// 执行并行查询
$results = PoolManager::parallelExecute([
    ['sql' => 'SELECT * FROM users WHERE id = ?', 'bindings' => [1]],
    ['sql' => 'SELECT * FROM posts WHERE user_id = ?', 'bindings' => [1]],
    ['sql' => 'SELECT * FROM comments WHERE post_id = ?', 'bindings' => [1]],
]);

// 批量执行任务
$results = PoolManager::batchExecute([
    fn() => Db::table('users')->find(1),
    fn() => Db::table('posts')->find(1),
    fn() => Db::table('comments')->find(1),
], 3);  // 最大并发数

// 清理过期连接
$pool->cleanup();

// 重置连接池
$pool->reset();

// 清除所有连接池
PoolManager::clear();
```

### 连接池类型说明

| 类型 | 说明 | 适用场景 |
|------|------|---------|
| `connection` | 普通连接池 | CLI、FastCGI、普通 Web 应用 |
| `process` | 进程池 | 多进程环境（pcntl_fork） |
| `parallel` | 并行池 | 多连接并行查询 |
| `fiber` | 协程池 | Swoole/Fiber 协程环境 |

### 进程池 (ProcessPool)

```php
use Kode\Database\Pool\ProcessPool;

$pool = new ProcessPool($config, 'default');

// 进程安全获取连接
$pid = getmypid();
$connection = $pool->get();  // fork 后自动重建

// 归还连接
$pool->release($connection);

// 获取统计
$stats = $pool->getStats();
// ['type' => 'process', 'total' => 10, 'available' => 5, 'process_id' => 1234]
```

### 并行池 (ParallelPool)

```php
use Kode\Database\Pool\ParallelPool;

$pool = new ParallelPool($config, 'default');

// 并行执行多个查询
$results = $pool->parallelExecute([
    ['sql' => 'SELECT * FROM users LIMIT 10', 'bindings' => []],
    ['sql' => 'SELECT * FROM posts LIMIT 10', 'bindings' => []],
    ['sql' => 'SELECT * FROM comments LIMIT 10', 'bindings' => []],
], function($sql, $bindings, $connection) {
    return $connection->select($sql, $bindings);
});

// 批量执行任务
$tasks = [
    fn() => Db::table('users')->count(),
    fn() => Db::table('posts')->count(),
    fn() => Db::table('comments')->count(),
];
$results = $pool->batchExecute($tasks, 5);  // 最大并发 5
```

### 协程池 (FiberPool)

```php
use Kode\Database\Pool\FiberPool;

$pool = new FiberPool($config, 'default');

// 设置使用 Swoole Channel（需要 Swoole 扩展）
$pool->setUseSwooleChannel(true);

// 在协程中获取连接
$fiber = new Fiber(function () {
    $connection = $pool->get();  // 协程隔离
    // 使用连接...
    $pool->release($connection);
});
$fiber->start();

// 获取当前协程的连接
$fiberConn = $pool->getFiberConnection();

// 获取统计
$stats = $pool->getStats();
// ['type' => 'fiber', 'total' => 20, 'available' => 10, 'fiber_connections' => 5, 'swoole_channel' => true]
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
│   ├── ModelNotFoundException.php
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

## 异常处理

```php
use Kode\Database\Exception\DatabaseException;
use Kode\Database\Exception\ModelNotFoundException;
use Kode\Database\Exception\QueryException;
use Kode\Database\Exception\ConnectionException;

// 查询异常
try {
    $result = Db::table('users')->where('id', 1)->first();
} catch (QueryException $e) {
    echo "SQL 错误: " . $e->getMessage();
    echo "SQL: " . $e->getSql();
    echo "参数: " . json_encode($e->getBindings());
}

// 使用静态方法创建异常
throw QueryException::tableNotFound('users');
throw QueryException::columnNotFound('email', 'users');
throw QueryException::sqlError('SELECT * FROM invalid', []);

// 连接异常
try {
    Db::connect('invalid_database');
} catch (ConnectionException $e) {
    echo "连接失败: " . $e->getMessage();
    echo "连接名: " . $e->getConnectionName();
    echo "主机: " . $e->getHost();
    echo "端口: " . $e->getPort();
}

// 使用静态方法创建异常
throw ConnectionException::cannotConnect('mysql', '127.0.0.1', 3306);
throw ConnectionException::timeout('mysql');
throw ConnectionException::refused('mysql', '127.0.0.1', 3306);

// 模型未找到异常
try {
    $user = Db::table('users')->findOrFail(['id' => 9999]);
} catch (ModelNotFoundException $e) {
    echo "未找到: " . $e->getMessage();
    echo "模型: " . $e->getModel();
}

// 使用静态方法创建异常
throw ModelNotFoundException::notFound('User');
throw ModelNotFoundException::make('User');

// 通用数据库异常
try {
    // 数据库操作
} catch (DatabaseException $e) {
    echo "数据库错误: " . $e->getMessage();
}

// 使用静态方法创建异常
throw DatabaseException::sql('SELECT * FROM users', [], 'Custom error message');
```

---

## License

Apache-2.0
