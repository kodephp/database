<?php

declare(strict_types=1);

namespace Kode\Database\Db;

use Kode\Database\Config\Driver;
use Kode\Database\Pool\PoolManager;
use Kode\Database\Query\QueryBuilder;

/**
 * 数据库连接封装类
 * 用于指定连接进行数据库操作
 * 支持跨库查询、多数据库连接、PostgreSQL、SQLite
 */
class Connection
{
    /** @var string 连接名称 */
    protected string $name;

    /** @var string|null 数据库名 */
    protected ?string $database;

    /** @var QueryBuilder|null 当前查询构建器（用于跨库Join） */
    protected ?QueryBuilder $queryBuilder = null;

    /** @var string 数据库驱动类型 */
    protected string $driver = 'mysql';

    /** @var mixed|null 跨库场景复用的底层连接（避免每次查询新建连接） */
    protected mixed $connection = null;

    /** @var array<int, string> 支持的驱动类型 */
    public const array SUPPORTED_DRIVERS = ['mysql', 'pgsql', 'sqlite', 'sqlsrv', 'oracle'];

    /** @var array<string, array<string, string|null>> 驱动特定方法映射 */
    public const array DRIVER_SPECIFIC_METHODS = [
        'pgsql' => [
            'lastInsertId' => 'lastval',
            'limit' => 'LIMIT',
            'offset' => 'OFFSET',
            'now' => 'NOW()',
            'random' => 'RANDOM()',
            'returning' => 'RETURNING',
        ],
        'sqlite' => [
            'lastInsertId' => 'last_insert_rowid',
            'limit' => 'LIMIT',
            'offset' => 'OFFSET',
            'now' => "datetime('now')",
            'random' => 'RANDOM()',
            'returning' => null,
        ],
        'mysql' => [
            'lastInsertId' => 'LAST_INSERT_ID()',
            'limit' => 'LIMIT',
            'offset' => 'OFFSET',
            'now' => 'NOW()',
            'random' => 'RAND()',
            'returning' => null,
        ],
        'sqlsrv' => [
            'lastInsertId' => 'SCOPE_IDENTITY()',
            'limit' => 'TOP',
            'offset' => 'OFFSET',
            'now' => 'GETDATE()',
            'random' => 'NEWID()',
            'returning' => 'OUTPUT',
        ],
        'oracle' => [
            'lastInsertId' => 'ROWID',
            'limit' => 'ROWNUM',
            'offset' => 'OFFSET',
            'now' => 'SYSDATE',
            'random' => 'DBMS_RANDOM.VALUE',
            'returning' => 'RETURNING',
        ],
    ];

    /**
     * 构造函数
     *
     * @param string $name 连接名称
     * @param string|null $database 数据库名
     */
    public function __construct(string $name, ?string $database = null)
    {
        $this->name = $name;
        $this->database = $database;
        $this->detectDriver();
    }

    /**
     * 检测数据库驱动类型
     *
     * 注意：driver 配置可能表示 ORM 连接器（laravel/pdo）或数据库类型（mysql/pgsql/...），
     * 这里必须解析为真正的数据库类型，否则 getLastInsertId / tableExists 等方言方法会误用 MySQL 语法。
     */
    protected function detectDriver(): void
    {
        $config = Db::getConfig($this->name);
        $this->driver = Driver::dbTypeFromConfig($config)->value;
    }

    /**
     * 获取当前驱动类型
     */
    public function getDriver(): string
    {
        return $this->driver;
    }

    /**
     * 检查是否为指定驱动
     */
    public function isDriver(string $driver): bool
    {
        return $this->driver === strtolower($driver);
    }

    /**
     * 检查是否支持指定驱动
     */
    public static function isSupportedDriver(string $driver): bool
    {
        return in_array(strtolower($driver), self::SUPPORTED_DRIVERS, true);
    }

    /**
     * 获取驱动特定方法
     */
    public function getDriverMethod(string $method): ?string
    {
        return self::DRIVER_SPECIFIC_METHODS[$this->driver][$method] ?? null;
    }

    /**
     * 获取所有驱动特定方法
     */
    public function getDriverMethods(): array
    {
        return self::DRIVER_SPECIFIC_METHODS[$this->driver] ?? [];
    }

    /**
     * 构建分页 SQL（驱动自适应）
     */
    public function buildPaginationSql(string $sql, ?int $limit = null, ?int $offset = null): string
    {
        if ($limit === null) {
            return $sql;
        }

        return match ($this->driver) {
            'pgsql', 'sqlite' => $this->buildOffsetPagination($sql, $limit, $offset),
            'sqlsrv' => $this->buildSqlsrvPagination($sql, $limit, $offset),
            default => $this->buildMysqlPagination($sql, $limit, $offset),
        };
    }

    /**
     * MySQL 分页
     */
    protected function buildMysqlPagination(string $sql, int $limit, ?int $offset): string
    {
        $sql .= " LIMIT {$limit}";
        if ($offset !== null) {
            $sql .= " OFFSET {$offset}";
        }
        return $sql;
    }

    /**
     * PostgreSQL/SQLite 分页
     */
    protected function buildOffsetPagination(string $sql, int $limit, ?int $offset): string
    {
        $sql .= " LIMIT {$limit}";
        if ($offset !== null) {
            $sql .= " OFFSET {$offset}";
        }
        return $sql;
    }

    /**
     * SQL Server 分页
     */
    protected function buildSqlsrvPagination(string $sql, int $limit, ?int $offset): string
    {
        $orderBy = '';
        if (preg_match('/ORDER\s+BY\s+([\w,\s\.]+)/i', $sql, $matches)) {
            $orderBy = $matches[0];
            $sql = preg_replace('/ORDER\s+BY\s+[\w,\s\.]+/i', '', $sql);
        }

        $offset = $offset ?? 0;
        $sql = "SELECT * FROM ({$sql}) AS t ORDER BY {$orderBy}";
        $sql .= " OFFSET {$offset} ROWS FETCH NEXT {$limit} ROWS ONLY";
        return $sql;
    }

    /**
     * 获取最后插入 ID（驱动自适应）
     */
    public function getLastInsertId(?string $sequence = null): string
    {
        return match ($this->driver) {
            'pgsql' => $this->select("SELECT lastval()")[0]['lastval'] ?? '0',
            'sqlite' => $this->select("SELECT last_insert_rowid() as id")[0]['id'] ?? '0',
            'sqlsrv' => $this->select("SELECT SCOPE_IDENTITY() as id")[0]['id'] ?? '0',
            default => $this->select("SELECT LAST_INSERT_ID() as id")[0]['id'] ?? '0',
        };
    }

    /**
     * 检查表是否存在（驱动自适应）
     */
    public function tableExists(string $table): bool
    {
        return match ($this->driver) {
            'pgsql' => $this->tableExistsPgsql($table),
            'sqlite' => $this->tableExistsSqlite($table),
            'sqlsrv' => $this->tableExistsSqlsrv($table),
            default => $this->tableExistsMysql($table),
        };
    }

    /**
     * MySQL 表存在检查
     */
    protected function tableExistsMysql(string $table): bool
    {
        $sql = "SHOW TABLES LIKE ?";
        $result = $this->select($sql, [$table]);
        return !empty($result);
    }

    /**
     * PostgreSQL 表存在检查
     */
    protected function tableExistsPgsql(string $table): bool
    {
        $sql = "SELECT EXISTS (SELECT FROM information_schema.tables WHERE table_name = ?) AS exists";
        $result = $this->select($sql, [$table]);
        return ($result[0]['exists'] ?? false) === true || $result[0]['exists'] === 't';
    }

    /**
     * SQLite 表存在检查
     */
    protected function tableExistsSqlite(string $table): bool
    {
        $sql = "SELECT name FROM sqlite_master WHERE type='table' AND name = ?";
        $result = $this->select($sql, [$table]);
        return !empty($result);
    }

    /**
     * SQL Server 表存在检查
     */
    protected function tableExistsSqlsrv(string $table): bool
    {
        $sql = "SELECT * FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_NAME = ?";
        $result = $this->select($sql, [$table]);
        return !empty($result);
    }

    /**
     * 获取表字段列表（驱动自适应）
     */
    public function getTableColumns(string $table): array
    {
        return match ($this->driver) {
            'pgsql' => $this->getTableColumnsPgsql($table),
            'sqlite' => $this->getTableColumnsSqlite($table),
            'sqlsrv' => $this->getTableColumnsSqlsrv($table),
            default => $this->getTableColumnsMysql($table),
        };
    }

    /**
     * MySQL 表字段
     */
    protected function getTableColumnsMysql(string $table): array
    {
        $sql = "SHOW COLUMNS FROM {$table}";
        $result = $this->select($sql);
        return array_column($result, 'Field');
    }

    /**
     * PostgreSQL 表字段
     */
    protected function getTableColumnsPgsql(string $table): array
    {
        $sql = "SELECT column_name FROM information_schema.columns WHERE table_name = ? ORDER BY ordinal_position";
        $result = $this->select($sql, [$table]);
        return array_column($result, 'column_name');
    }

    /**
     * SQLite 表字段
     */
    protected function getTableColumnsSqlite(string $table): array
    {
        $sql = "PRAGMA table_info({$table})";
        $result = $this->select($sql);
        return array_column($result, 'name');
    }

    /**
     * SQL Server 表字段
     */
    protected function getTableColumnsSqlsrv(string $table): array
    {
        $sql = "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME = ? ORDER BY ORDINAL_POSITION";
        $result = $this->select($sql, [$table]);
        return array_column($result, 'COLUMN_NAME');
    }

    /**
     * 获取当前数据库名
     */
    public function getCurrentDatabase(): ?string
    {
        return match ($this->driver) {
            'pgsql' => $this->select('SELECT current_database() as db')[0]['db'] ?? null,
            'sqlite' => $this->database,
            'sqlsrv' => $this->select('SELECT DB_NAME() as db')[0]['db'] ?? null,
            default => $this->select('SELECT DATABASE() as db')[0]['db'] ?? null,
        };
    }

    /**
     * 获取服务器版本
     */
    public function getVersion(): string
    {
        return match ($this->driver) {
            'pgsql' => $this->select('SELECT version()')[0]['version'] ?? '',
            'sqlite' => 'SQLite ' . ($this->select('SELECT sqlite_version() as v')[0]['v'] ?? ''),
            'sqlsrv' => $this->select('SELECT @@VERSION as v')[0]['v'] ?? '',
            default => $this->select('SELECT VERSION() as version')[0]['version'] ?? '',
        };
    }

    /**
     * 获取查询构建器
     *
     * @param string $table 表名
     * @return QueryBuilder
     * @example Db::connection('slave')->table('users')->get()
     */
    public function table(string $table): QueryBuilder
    {
        $connection = $this->getConnection();
        return (new QueryBuilder($connection))->from($table);
    }

    /**
     * 执行 SQL 查询
     *
     * @param string $sql SQL语句
     * @param array $bindings 参数绑定
     * @return array
     */
    public function select(string $sql, array $bindings = []): array
    {
        $connection = $this->getConnection();
        return $connection->select($sql, $bindings);
    }

    /**
     * 执行插入
     *
     * @param string $sql SQL语句
     * @param array $bindings 参数绑定
     * @return int|string 最后插入的 ID（PDO::lastInsertId）
     */
    public function insert(string $sql, array $bindings = []): int|string
    {
        $connection = $this->getConnection();
        return $connection->insert($sql, $bindings);
    }

    /**
     * 执行更新
     *
     * @param string $sql SQL语句
     * @param array $bindings 参数绑定
     * @return int
     */
    public function update(string $sql, array $bindings = []): int
    {
        $connection = $this->getConnection();
        return $connection->update($sql, $bindings);
    }

    /**
     * 执行删除
     *
     * @param string $sql SQL语句
     * @param array $bindings 参数绑定
     * @return int
     */
    public function delete(string $sql, array $bindings = []): int
    {
        $connection = $this->getConnection();
        return $connection->delete($sql, $bindings);
    }

    /**
     * 执行语句
     *
     * @param string $sql SQL语句
     * @param array $bindings 绑定参数
     * @return bool
     */
    public function statement(string $sql, array $bindings = []): bool
    {
        $connection = $this->getConnection();
        return $connection->statement($sql, $bindings);
    }

    /**
     * 开启事务
     */
    public function beginTransaction(): void
    {
        $connection = $this->getConnection();
        $connection->beginTransaction();
        Db::enterTransaction();
    }

    /**
     * 提交事务
     */
    public function commit(): void
    {
        $connection = $this->getConnection();
        $connection->commit();
        Db::leaveTransaction();
    }

    /**
     * 回滚事务
     */
    public function rollback(): void
    {
        $connection = $this->getConnection();
        $connection->rollBack();
        Db::leaveTransaction();
    }

    /**
     * 事务执行
     *
     * @param callable $callback 回调函数
     * @return mixed
     */
    public function transaction(callable $callback): mixed
    {
        $this->beginTransaction();
        try {
            $result = $callback($this);
            $this->commit();
            return $result;
        } catch (\Throwable $e) {
            $this->rollback();
            throw $e;
        }
    }

    /**
     * 获取连接
     */
    public function getConnection(): mixed
    {
        $config = Db::getConfig($this->name);

        // 跨库场景（useDatabase 指定了 database）需独立连接，避免污染共享连接的库名
        if ($this->database !== null) {
            if ($this->connection !== null) {
                return $this->connection;
            }

            if (PoolManager::isPoolEnabled($config) && PoolManager::hasPool($this->name)) {
                $connection = PoolManager::getConnection($this->name);
            } elseif (PoolManager::isPoolEnabled($config)) {
                // 池已启用但尚未 hasPool（罕见），尝试懒获取，失败则回退直连
                try {
                    $connection = PoolManager::getConnection($this->name);
                } catch (\Throwable) {
                    $factory = new \Kode\Database\Connection\ConnectionFactory();
                    $connection = $factory->make($config);
                }
            } else {
                // 无池退化：跨库场景需独立连接，避免污染单例的库名，故直连（不走单例池共享）
                $factory = new \Kode\Database\Connection\ConnectionFactory();
                $connection = $factory->make($config);
            }

            $connection->setDatabase($this->database);
            $this->connection = $connection;

            return $connection;
        }

        // 普通场景复用 Db 的连接缓存，保证同一连接名在事务内使用同一底层 PDO
        return Db::getConnection($this->name);
    }

    /**
     * 获取连接名称
     */
    public function getName(): string
    {
        return $this->name;
    }

    /**
     * 获取数据库名
     */
    public function getDatabase(): ?string
    {
        return $this->database;
    }

    /**
     * 跨库 Join 查询
     * 支持 db.table 格式指定不同数据库的表
     *
     * @param string $table 主表名
     * @param string $dbTable 跨库表，格式: "database.table" 或 "database.table as alias"
     * @param string $first 第一个字段
     * @param string $operator 操作符
     * @param string $second 第二个字段
     * @param string $type Join 类型 (INNER, LEFT, RIGHT)
     * @return $this
     * @example Db::connection('default')->crossJoin('users', 'shop.products', 'users.shop_id', '=', 'shop.id')->get()
     */
    public function crossJoin(string $table, string $dbTable, string $first, string $operator, string $second, string $type = 'INNER'): static
    {
        $this->queryBuilder = $this->table($table);
        $this->queryBuilder->join($dbTable, $first, $operator, $second, $type);
        return $this;
    }

    /**
     * 切换数据库
     *
     * @param string $database 数据库名
     * @return $this
     */
    public function useDatabase(string $database): static
    {
        $this->database = $database;
        return $this;
    }

    /**
     * 获取表名（带数据库前缀）
     *
     * @param string $table 表名
     * @return string
     */
    public function qualify(string $table): string
    {
        if ($this->database !== null) {
            return "`{$this->database}`.`{$table}`";
        }
        return "`{$table}`";
    }

    /**
     * 获取查询构建器（带数据库上下文）
     *
     * @return QueryBuilder
     */
    public function query(): QueryBuilder
    {
        return new QueryBuilder($this->getConnection());
    }

    /**
     * 执行原始查询
     *
     * @param string $sql SQL 语句
     * @param array $bindings 参数
     * @return array
     */
    public function raw(string $sql, array $bindings = []): array
    {
        return $this->select($sql, $bindings);
    }

    /**
     * 获取数据库名
     *
     * @return string|null
     */
    public function getDatabaseName(): ?string
    {
        return $this->database;
    }

    /**
     * 克隆连接（保留配置）
     *
     * @return static
     */
    public function copy(): static
    {
        return new static($this->name, $this->database);
    }

    /**
     * 查询钩子回调
     *
     * 按「连接名 => 回调列表」存放，'*' 表示对所有连接生效。
     * 触发点在执行器的观测切面（{@see \Kode\Database\Connection\QueryObservation}），
     * 所以走 Db::connection()->select()、QueryBuilder 还是裸执行器，
     * 内置 PDO 执行器还是某个 ORM 桥接器，都会经过同一处。
     *
     * @var array<string, list<callable>>
     */
    protected static array $beforeQueryCallbacks = [];
    /** @var array<string, list<callable>> */
    protected static array $afterQueryCallbacks = [];

    /**
     * 注册查询前钩子
     *
     * @param callable $callback 签名：(string $sql, array $bindings, object $executor)
     *                           返回 [$sql, $bindings?] 可改写本次查询，否则原样放行
     * @param string|null $connectionName 连接名，为 null 表示所有连接
     */
    public static function beforeQuery(callable $callback, ?string $connectionName = null): void
    {
        self::$beforeQueryCallbacks[$connectionName ?? '*'][] = $callback;
    }

    /**
     * 注册查询后钩子
     *
     * @param callable $callback 签名：(string $sql, array $bindings, mixed $result, object $executor, float $seconds)
     *                           执行失败时 $result 为 \Throwable
     * @param string|null $connectionName 连接名，为 null 表示所有连接
     */
    public static function afterQuery(callable $callback, ?string $connectionName = null): void
    {
        self::$afterQueryCallbacks[$connectionName ?? '*'][] = $callback;
    }

    /**
     * 注册查询钩子（一次登记前后两个）
     *
     * @param array{before?:callable,after?:callable} $hooks
     * @param string|null $connectionName 连接名
     */
    public static function registerQueryHook(array $hooks, ?string $connectionName = null): void
    {
        if (isset($hooks['before']) && is_callable($hooks['before'])) {
            self::beforeQuery($hooks['before'], $connectionName);
        }

        if (isset($hooks['after']) && is_callable($hooks['after'])) {
            self::afterQuery($hooks['after'], $connectionName);
        }
    }

    /**
     * 触发「查询前」钩子。
     *
     * 钩子返回 [$sql, $bindings]（或 [$sql]）时改写本次查询，没返回就原样放行 ——
     * 给「加 trace 注释 / 换表名」这类用法留着口子。只关心查询的钩子直接 return; 即可。
     *
     * @param array<string> $keys 依次查找的注册键（连接名、'*'）
     *
     * @return array{0: string, 1: array} 生效的 SQL 与参数
     */
    public static function fireBeforeQuery(array $keys, string $sql, array $bindings, object $executor): array
    {
        foreach ($keys as $key) {
            foreach (self::$beforeQueryCallbacks[$key] ?? [] as $callback) {
                $rewritten = $callback($sql, $bindings, $executor);
                if (is_array($rewritten) && isset($rewritten[0]) && is_string($rewritten[0])) {
                    $sql = $rewritten[0];
                    $bindings = array_key_exists(1, $rewritten) ? (array) $rewritten[1] : $bindings;
                }
            }
        }

        return [$sql, $bindings];
    }

    /**
     * 触发「查询后」钩子
     *
     * @param array<string> $keys
     */
    public static function fireAfterQuery(array $keys, string $sql, array $bindings, mixed $result, object $executor, float $seconds): void
    {
        foreach ($keys as $key) {
            foreach (self::$afterQueryCallbacks[$key] ?? [] as $callback) {
                $callback($sql, $bindings, $result, $executor, $seconds);
            }
        }
    }

    /**
     * 获取所有查询前钩子
     */
    public static function getBeforeQueryHooks(?string $connectionName = null): array
    {
        $key = $connectionName ?? '*';
        return self::$beforeQueryCallbacks[$key] ?? [];
    }

    /**
     * 获取所有查询后钩子
     */
    public static function getAfterQueryHooks(?string $connectionName = null): array
    {
        $key = $connectionName ?? '*';
        return self::$afterQueryCallbacks[$key] ?? [];
    }

    /**
     * 是否登记过任何查询钩子（执行器据此决定要不要做观测）
     */
    public static function hasQueryHooks(): bool
    {
        return self::$beforeQueryCallbacks !== [] || self::$afterQueryCallbacks !== [];
    }

    /**
     * 清除查询钩子
     */
    public static function clearQueryHooks(?string $connectionName = null): void
    {
        if ($connectionName === null) {
            self::$beforeQueryCallbacks = [];
            self::$afterQueryCallbacks = [];
        } else {
            unset(self::$beforeQueryCallbacks[$connectionName]);
            unset(self::$afterQueryCallbacks[$connectionName]);
        }
    }

    /**
     * 检查连接是否正常
     *
     * @return bool
     */
    public function isConnected(): bool
    {
        try {
            $connection = $this->getConnection();
            return $connection->isConnected();
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Ping 数据库检查连接
     *
     * @return bool
     */
    public function ping(): bool
    {
        try {
            $this->statement('SELECT 1');
            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * 获取所有表名
     *
     * @return array
     */
    public function getTables(): array
    {
        $result = $this->select('SHOW TABLES');
        if (empty($result)) {
            return [];
        }
        $key = array_key_first($result[0]);
        return array_column($result, $key);
    }

    /**
     * 获取表的主键字段
     *
     * @param string $table 表名
     * @return array
     */
    public function getPrimaryKey(string $table): array
    {
        $result = $this->select("SHOW INDEX FROM {$table} WHERE Key_name = 'PRIMARY'");
        return array_column($result, 'Column_name');
    }

    /**
     * 获取表的索引信息
     *
     * @param string $table 表名
     * @return array
     */
    public function getIndexes(string $table): array
    {
        return $this->select("SHOW INDEX FROM {$table}");
    }
}
