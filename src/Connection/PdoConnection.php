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
 */
class PdoConnection implements ExecutorInterface
{
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
     * 根据配置构建 PDO DSN
     *
     * 数据库类型解析优先级：pdo_driver（显式）> database_driver（规范化）> driver（兼容旧写法）。
     * 这样即便 driver 被用作 ORM 连接器选择器（如 'pdo'），也能正确生成目标数据库 DSN，
     * 使内置 PDO 执行器支持 mysql / pgsql / sqlite / sqlsrv / oracle 全部数据库。
     */
    protected function buildDsn(): string
    {
        $dbType = strtolower(
            $this->config['pdo_driver']
            ?? $this->config['database_driver']
            ?? $this->config['driver']
            ?? 'mysql'
        );
        $pdoDriver = self::PDO_DRIVERS[$dbType] ?? 'mysql';
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

    #[\Override]
    public function select(string $sql, array $bindings = []): array
    {
        $execute = function () use ($sql, $bindings): array {
            $stmt = $this->prepareStatement($sql);
            $stmt->execute($bindings);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        };

        try {
            return $execute();
        } catch (PDOException) {
            $this->disconnect();
            return $execute();
        }
    }

    #[\Override]
    public function insert(string $sql, array $bindings = []): int|string
    {
        $execute = function () use ($sql, $bindings): int|string {
            $stmt = $this->prepareStatement($sql);
            $stmt->execute($bindings);
            return $this->ensureConnected()->lastInsertId();
        };

        try {
            return $execute();
        } catch (PDOException) {
            $this->disconnect();
            return $execute();
        }
    }

    #[\Override]
    public function update(string $sql, array $bindings = []): int
    {
        $execute = function () use ($sql, $bindings): int {
            $stmt = $this->prepareStatement($sql);
            $stmt->execute($bindings);
            return $stmt->rowCount();
        };

        try {
            return $execute();
        } catch (PDOException) {
            $this->disconnect();
            return $execute();
        }
    }

    #[\Override]
    public function delete(string $sql, array $bindings = []): int
    {
        $execute = function () use ($sql, $bindings): int {
            $stmt = $this->prepareStatement($sql);
            $stmt->execute($bindings);
            return $stmt->rowCount();
        };

        try {
            return $execute();
        } catch (PDOException) {
            $this->disconnect();
            return $execute();
        }
    }

    #[\Override]
    public function statement(string $sql): bool
    {
        $this->ensureConnected()->exec($sql);
        return true;
    }

    #[\Override]
    public function beginTransaction(): void
    {
        if ($this->transactionLevel === 0) {
            $this->ensureConnected()->beginTransaction();
        }
        $this->transactionLevel++;
    }

    #[\Override]
    public function commit(): void
    {
        if ($this->transactionLevel > 0) {
            $this->transactionLevel--;
        }
        if ($this->transactionLevel === 0 && $this->pdo !== null) {
            $this->pdo->commit();
        }
    }

    #[\Override]
    public function rollBack(): void
    {
        if ($this->transactionLevel > 0) {
            $this->transactionLevel--;
        }
        if ($this->transactionLevel === 0 && $this->pdo !== null) {
            $this->pdo->rollBack();
        }
    }

    #[\Override]
    public function isConnected(): bool
    {
        if ($this->pdo === null) {
            return false;
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
