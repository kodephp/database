<?php

declare(strict_types=1);

namespace Kode\Database\Connection;

use PDO;
use PDOException;

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
    /** 支持的 PDO 驱动 -> PDO DSN 驱动名 */
    private const PDO_DRIVERS = [
        'mysql' => 'mysql',
        'pgsql' => 'pgsql',
        'postgres' => 'pgsql',
        'postgresql' => 'pgsql',
        'sqlite' => 'sqlite',
        'sqlsrv' => 'sqlsrv',
        'sqlserver' => 'sqlsrv',
        'dblib' => 'sqlsrv',
    ];

    protected array $config;
    protected ?PDO $pdo = null;
    protected int $transactionLevel = 0;

    public function __construct(array $config)
    {
        $this->config = $config;
    }

    /**
     * 建立（或复用）底层 PDO 连接
     */
    protected function ensureConnected(): PDO
    {
        if ($this->pdo !== null && $this->isConnected()) {
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
     */
    protected function buildDsn(): string
    {
        $driver = strtolower($this->config['driver'] ?? 'mysql');
        $pdoDriver = self::PDO_DRIVERS[$driver] ?? 'mysql';
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
            default => sprintf(
                'mysql:host=%s;port=%s;dbname=%s;charset=%s',
                $this->config['host'] ?? '127.0.0.1',
                $this->config['port'] ?? 3306,
                $this->config['database'] ?? '',
                $charset
            ),
        };
    }

    #[\Override]
    public function select(string $sql, array $bindings = []): array
    {
        $stmt = $this->ensureConnected()->prepare($sql);
        $stmt->execute($bindings);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    #[\Override]
    public function insert(string $sql, array $bindings = []): int|string
    {
        $stmt = $this->ensureConnected()->prepare($sql);
        $stmt->execute($bindings);
        return $this->ensureConnected()->lastInsertId();
    }

    #[\Override]
    public function update(string $sql, array $bindings = []): int
    {
        $stmt = $this->ensureConnected()->prepare($sql);
        $stmt->execute($bindings);
        return $stmt->rowCount();
    }

    #[\Override]
    public function delete(string $sql, array $bindings = []): int
    {
        $stmt = $this->ensureConnected()->prepare($sql);
        $stmt->execute($bindings);
        return $stmt->rowCount();
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
    }

    #[\Override]
    public function getConfig(): array
    {
        return $this->config;
    }
}
