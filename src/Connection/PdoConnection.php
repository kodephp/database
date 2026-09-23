<?php

declare(strict_types=1);

namespace Kode\Database\Connection;

use PDO;
use PDOException;
use PDOStatement;

/**
 * 内置 PDO 执行器（通用回退实现）
 *
 * 当开发者未安装任何 ORM 包时，所有连接器都会回退到本类，
 * 使 kode/database 开箱即可连接 MySQL / PostgreSQL / SQLite / SQL Server。
 *
 * 同时，Laravel / ThinkPHP / Symfony / Hyperf 连接器在检测到对应 ORM 时，
 * 会优先复用该 ORM 的连接管理器（通过各自的 Bridge 类），否则同样回退到本类。
 *
 * 查询观测（钩子 / 日志 / 事件）来自 {@see QueryObservation}，与各 ORM 桥接器共用同一实现。
 */
class PdoConnection implements ExecutorInterface
{
    use QueryObservation;

    /** 支持的数据库类型别名 -> PDO DSN 驱动名 */
    private const PDO_DRIVERS = [
        'mysql' => 'mysql',
        'pgsql' => 'pgsql',
        'postgres' => 'pgsql',
        'postgresql' => 'pgsql',
        'sqlite' => 'sqlite',
        'sqlsrv' => 'sqlsrv',
        'sqlserver' => 'sqlsrv',
        'dblib' => 'sqlsrv',
        'oracle' => 'oci',
        'oci' => 'oci',
    ];

    /** 预编译语句缓存条数上限（超出时不缓存，但仍返回可用语句） */
    private const STMT_CACHE_LIMIT = 256;

    /** 可重试的驱动层错误码（断链/服务不可达，重连后重试有意义） */
    private const RETRYABLE_DRIVER_CODES = [2002, 2003, 2006, 2013, 2045, 2101];

    /**
     * pdo_pgsql 的连接级故障 SQLSTATE
     *
     * HY000 是 libpq 层错误（后端被终止、服务端关闭连接、连接已不存在）的唯一落点；
     * 57P01/57P02/57P03 是服务端 FATAL（管理员关机/崩溃关机/暂不可连），三类一律终结会话。
     */
    private const PGSQL_CONNECTION_SQLSTATES = ['HY000', '57P01', '57P02', '57P03'];

    protected array $config;
    protected ?PDO $pdo = null;
    protected int $transactionLevel = 0;

    /**
     * 预编译语句缓存（按 SQL 文本缓存 PDOStatement）
     *
     * 常驻内存场景下复用同一 SQL 的 PDOStatement，避免每次查询都走一次 PREPARE
     * 网络往返；语义对齐 Doctrine/Laravel 的语句缓存。上限 STMT_CACHE_LIMIT（256）
     * 条，disconnect() 时清空。
     *
     * @var array<string, PDOStatement>
     */
    protected array $stmtCache = [];

    public function __construct(array $config)
    {
        $this->config = $config;
    }

    /**
     * 建立（或复用）底层 PDO 连接
     *
     * 常驻内存下不再每次查询发 SELECT 1 探活（消除每查 1 次的额外 DB 往返），
     * 已连接即直接复用；连接失效由执行语句时抛出的 PDOException 暴露，
     * 由 select/insert/update/delete 捕获后断连重试一次（行为不变、更省）。
     */
    protected function ensureConnected(): PDO
    {
        if ($this->pdo !== null) {
            return $this->pdo;
        }

        $dsn = $this->buildDsn();
        $username = $this->config['username'] ?? $this->config['user'] ?? null;
        $password = $this->config['password'] ?? $this->config['pass'] ?? null;

        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ];

        if (isset($this->config['options']) && is_array($this->config['options'])) {
            $options = array_replace($options, $this->config['options']);
        }

        try {
            $this->pdo = new PDO($dsn, $username, $password, $options);
        } catch (PDOException $e) {
            throw new \Kode\Database\Exception\ConnectionException(
                '',
                'PDO 连接失败: ' . $e->getMessage(),
                (int) $e->getCode(),
                $e
            );
        }

        return $this->pdo;
    }

    /**
     * 解析本连接实际使用的 PDO 驱动名（mysql / pgsql / sqlite / sqlsrv / oci）
     *
     * 数据库类型解析优先级：pdo_driver（显式）> database_driver（规范化）> driver（兼容旧写法）。
     * 这样即便 driver 被用作 ORM 连接器选择器（如 'pdo'），也能正确识别目标数据库。
     * buildDsn() 与连接故障判定共用本方法，避免两处口径漂移。
     */
    protected function pdoDriver(): string
    {
        $dbType = strtolower(
            (string) ($this->config['pdo_driver']
                ?? $this->config['database_driver']
                ?? $this->config['driver']
                ?? 'mysql')
        );

        return self::PDO_DRIVERS[$dbType] ?? 'mysql';
    }

    /**
     * 根据配置构建 PDO DSN
     *
     * 驱动名解析见 {@see pdoDriver()}；内置 PDO 执行器支持
     * mysql / pgsql / sqlite / sqlsrv / oracle 全部数据库。
     */
    protected function buildDsn(): string
    {
        $pdoDriver = $this->pdoDriver();
        $charset = $this->config['charset'] ?? 'utf8mb4';

        return match ($pdoDriver) {
            'sqlite' => 'sqlite:' . ($this->config['database'] ?? ':memory:'),
            'pgsql' => sprintf(
                'pgsql:host=%s;port=%s;dbname=%s',
                $this->config['host'] ?? '127.0.0.1',
                $this->config['port'] ?? 5432,
                $this->config['database'] ?? ''
            ),
            'sqlsrv' => sprintf(
                'sqlsrv:Server=%s,%s;Database=%s',
                $this->config['host'] ?? 'localhost',
                $this->config['port'] ?? 1433,
                $this->config['database'] ?? ''
            ),
            'oci' => sprintf(
                'oci:dbname=//%s:%s/%s;charset=%s',
                $this->config['host'] ?? '127.0.0.1',
                $this->config['port'] ?? 1521,
                $this->config['database'] ?? 'XE',
                $charset
            ),
            default => sprintf(
                'mysql:host=%s;port=%s;dbname=%s;charset=%s',
                $this->config['host'] ?? '127.0.0.1',
                $this->config['port'] ?? 3306,
                $this->config['database'] ?? '',
                $charset
            ),
        };
    }

    /**
     * 准备（或复用缓存的）PDO 预编译语句
     *
     * 同一 SQL 文本命中缓存则直接返回，避免常驻内存下重复 PREPARE 网络往返；
     * 缓存满 STMT_CACHE_LIMIT（256）条时不缓存但仍返回可用语句。
     */
    protected function prepareStatement(string $sql): PDOStatement
    {
        if (isset($this->stmtCache[$sql])) {
            return $this->stmtCache[$sql];
        }

        $stmt = $this->ensureConnected()->prepare($sql);

        if (count($this->stmtCache) < self::STMT_CACHE_LIMIT) {
            $this->stmtCache[$sql] = $stmt;
        }

        return $stmt;
    }

    /**
     * 判断 PDOException 是否为连接类故障（只有这类才值得断连重试）
     *
     * SQL 语法错误、约束冲突等业务性错误重放一次只会重复失败并白白丢弃连接，
     * 因此不再对任意 PDOException 盲目重试。
     *
     * $driver 为本连接的 PDO 驱动名（见 {@see pdoDriver()}）；留空则跳过驱动专属判定，
     * 保持只按 SQLSTATE 08xxx / 超时 / MySQL 驱动码的旧口径。
     */
    protected static function isConnectionFailure(PDOException $e, string $driver = ''): bool
    {
        $sqlState = (string) ($e->errorInfo[0] ?? $e->getCode());
        // SQLSTATE 08xxx = connection exception；HYT00/HYT01 = 超时
        if (str_starts_with($sqlState, '08') || $sqlState === 'HYT00' || $sqlState === 'HYT01') {
            return true;
        }

        // pdo_pgsql 对**所有**错误回吐驱动码 7（业务错误也一样），所以上面那套 MySQL
        // 驱动码在 pgsql 上完全失效；而它把「后端被终止/服务端关闭连接」报成 SQLSTATE
        // HY000，不在 08xxx 类里。两条路都走不通，于是断链永不进重连分支：常驻 worker
        // 只要遇到笔记本睡眠 / pg_terminate_backend / 空闲超时杀连接，之后每条 SQL 都
        // 永久抛错直到重启整个服务（CLI 侧同配置新建连接却正常，最具迷惑性）。
        // 实测同实例：pgsql 的业务错误一律带真实 SQLSTATE（42P01/42601/42703/22012/
        // 22P02/23505/23503/23502/22001/57014/25P02），不会落进 HY000，故可按驱动判定。
        //
        // 门控必须是驱动名而不是直接全局认 HY000：pdo_sqlite 把「表不存在/语法错」也
        // 报成 HY000（实测驱动码 1），全局放行会让 sqlite 每条业务错误都白断连重放一次。
        //
        // 重放语义与 MySQL 路径一致：至多重试一次，且只在新连接上执行。断链前的写入
        // 是否已被服务端提交无法得知，所以本路径是 at-least-once，不幂等的写请求仍可能
        // 重复——这是「永不重连（整进程砖化）」的取舍，业务侧靠幂等键自行兜底。
        if ($driver === 'pgsql' && in_array($sqlState, self::PGSQL_CONNECTION_SQLSTATES, true)) {
            return true;
        }

        return in_array((int) ($e->errorInfo[1] ?? 0), self::RETRYABLE_DRIVER_CODES, true);
    }

    /**
     * 执行一次 SQL，连接类故障时断连重试一次（非连接类故障直接抛出，不重复执行）。
     *
     * 事务中途断链**不自动重连**：新连接上没有 BEGIN，剩余语句会被逐条自动提交，
     * 且外层 rollBack() 因 PDO inTransaction() 为 false 而空转 —— 静默把原子单元
     * 变成「半提交」。这种情况原样抛出，让调用方的事务边界失败。
     *
     * @param callable(): mixed $execute
     */
    protected function retryOnce(callable $execute): mixed
    {
        try {
            return $execute();
        } catch (PDOException $e) {
            if ($this->transactionLevel > 0 || !self::isConnectionFailure($e, $this->pdoDriver())) {
                throw $e;
            }
            $this->disconnect();

            return $execute();
        }
    }

    #[\Override]
    public function select(string $sql, array $bindings = []): array
    {
        return $this->observe($sql, $bindings, function (string $sql, array $bindings): array {
            return $this->retryOnce(function () use ($sql, $bindings): array {
                $stmt = $this->prepareStatement($sql);
                $stmt->execute($bindings);

                return $stmt->fetchAll(PDO::FETCH_ASSOC);
            });
        });
    }

    #[\Override]
    public function insert(string $sql, array $bindings = []): int|string
    {
        return $this->observe($sql, $bindings, function (string $sql, array $bindings): int|string {
            return $this->retryOnce(function () use ($sql, $bindings): int|string {
                $stmt = $this->prepareStatement($sql);
                $stmt->execute($bindings);

                return $this->ensureConnected()->lastInsertId();
            });
        });
    }

    #[\Override]
    public function update(string $sql, array $bindings = []): int
    {
        return $this->observe($sql, $bindings, function (string $sql, array $bindings): int {
            return $this->retryOnce(function () use ($sql, $bindings): int {
                $stmt = $this->prepareStatement($sql);
                $stmt->execute($bindings);

                return $stmt->rowCount();
            });
        });
    }

    #[\Override]
    public function delete(string $sql, array $bindings = []): int
    {
        return $this->observe($sql, $bindings, function (string $sql, array $bindings): int {
            return $this->retryOnce(function () use ($sql, $bindings): int {
                $stmt = $this->prepareStatement($sql);
                $stmt->execute($bindings);

                return $stmt->rowCount();
            });
        });
    }

    #[\Override]
    public function statement(string $sql, array $bindings = []): bool
    {
        return $this->observe($sql, $bindings, function (string $sql, array $bindings): bool {
            // 走 prepare/execute 以支持占位符绑定；无绑定参数时与原 exec 行为等价
            return $this->retryOnce(function () use ($sql, $bindings): bool {
                $stmt = $this->prepareStatement($sql);
                $stmt->execute($bindings);

                return true;
            });
        });
    }

    #[\Override]
    public function beginTransaction(): void
    {
        // 若上一级事务被数据库隐式提交（例如 PostgreSQL 执行 DDL 后会强制提交当前事务），
        // PDO 侧 inTransaction() 已为 false 但 $this->transactionLevel 未回落到 0，
        // 此时继续递增 level 会导致后续 rollBack 在错误的层级被跳过，实际事务已断。
        // 先做一致性校验：level 与 PDO 实际状态不一致时，重置 level 再开启新事务。
        $pdo = $this->ensureConnected();
        if ($this->transactionLevel > 0 && !$pdo->inTransaction()) {
            $this->transactionLevel = 0;
        }
        if ($this->transactionLevel === 0) {
            // BEGIN 必须自己具备重连能力：事务型请求的第一条语句就是 BEGIN，若它不做
            // 断连重试，「断链之后的第一个事务」会永久停在裸错误上（retryOnce 那套判定
            // 根本轮不到），常驻 worker 又回到「一旦断链就再也起不来」的状态。
            // 空事务重放没有副作用，因此这里是安全的。
            try {
                $pdo->beginTransaction();
            } catch (PDOException $e) {
                if (!self::isConnectionFailure($e, $this->pdoDriver())) {
                    throw $e;
                }
                $this->disconnect();
                $this->ensureConnected()->beginTransaction();
            }
        }
        $this->transactionLevel++;
    }

    #[\Override]
    public function commit(): void
    {
        if ($this->transactionLevel > 0) {
            $this->transactionLevel--;
        }
        if ($this->transactionLevel === 0 && $this->pdo !== null && $this->pdo->inTransaction()) {
            $this->pdo->commit();
        }
    }

    #[\Override]
    public function rollBack(): void
    {
        // 与 commit 对称：以 PDO 实际状态为准，避免上层误以为事务开启但底层已中断
        // 时，直接 PDO::rollBack() 抛 "There is no active transaction"。
        if ($this->transactionLevel > 0) {
            $this->transactionLevel--;
        }
        if ($this->transactionLevel === 0 && $this->pdo !== null && $this->pdo->inTransaction()) {
            // 断链后的 rollBack 一定失败（后端已经没了，事务也随进程一起没了）。
            // 这里必须吞掉「连接级」异常并丢弃死句柄：调用方通常在 catch 里回滚，
            // 让 rollBack 的二次异常冒泡会盖掉真正的失败原因，只留一句
            // "no connection to the server"，故障现场无从定位。
            // commit 不做同样处理——提交没成功必须让调用方知道。
            try {
                $this->pdo->rollBack();
            } catch (PDOException $e) {
                if (!self::isConnectionFailure($e, $this->pdoDriver())) {
                    throw $e;
                }
                // 只在真的丢弃句柄时清缓存：回滚成功是热路径，不能顺手把连接和语句缓存扔掉
                $this->disconnect();
            }
        }
    }

    #[\Override]
    public function isConnected(): bool
    {
        if ($this->pdo === null) {
            // 惰性连接（尚未建立 PDO）视为可用，避免池化场景下 fresh 连接被误判为失效而丢弃
            // 真正失效会在 query 时通过 PDOException 暴露并触发重连
            return true;
        }

        try {
            return $this->pdo->query('SELECT 1') !== false;
        } catch (PDOException) {
            return false;
        }
    }

    #[\Override]
    public function setDatabase(string $database): void
    {
        $this->config['database'] = $database;
        $this->disconnect();
    }

    #[\Override]
    public function disconnect(): void
    {
        $this->pdo = null;
        $this->transactionLevel = 0;
        $this->stmtCache = [];
    }

    #[\Override]
    public function getConfig(): array
    {
        return $this->config;
    }
}
