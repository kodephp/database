<?php

declare(strict_types=1);

namespace Kode\Database\Database\Migrations;

use Kode\Database\Db\Db;

/**
 * 迁移运行器（框架无关）
 *
 * 扫描迁移目录下的迁移文件，按文件名（时间戳前缀）排序后依次执行，
 * 并将执行情况记录到 migrations 表，支持回滚到指定批次。
 */
class Migrator
{
    protected string $table = 'migrations';

    /**
     * @param string $migrationsPath 迁移文件目录
     * @param string|null $connection 连接名（null 表示默认）
     */
    public function __construct(
        protected string $migrationsPath,
        protected ?string $connection = null
    ) {
    }

    /**
     * 设置迁移记录表名
     */
    public function setTable(string $table): static
    {
        $this->table = $table;
        return $this;
    }

    /**
     * 在指定连接的作用域内执行回调。
     *
     * `Db::` 门面（statement/select/insert/beginTransaction…）一律走默认连接，
     * 而迁移文件内部写的也是 `Db::statement(...)` —— 所以只把 Migrator 自己的
     * 几条语句带上 $connection 是不够的：记账会记到指定库，建表却落到默认库，
     * 一半在 A 库一半在 B 库。把默认连接临时切过去，才是「迁移跑在这个库上」。
     *
     * @template T
     * @param callable(): T $fn
     * @return T
     */
    private function onConnection(callable $fn): mixed
    {
        if ($this->connection === null || $this->connection === '') {
            return $fn();
        }

        $previous = Db::getDefaultConnection();
        Db::setDefaultConnection($this->connection);
        try {
            return $fn();
        } finally {
            // 常驻进程里漏一次还原，之后的所有默认连接查询都会跑到迁移库上
            Db::setDefaultConnection($previous);
        }
    }

    /**
     * 确保 migrations 表存在
     *
     * 根据数据库方言生成对应的建表语句，使迁移系统在 MySQL / PostgreSQL / SQLite / SQL Server 上均可运行。
     */
    public function ensureMigrationsTable(): void
    {
        $this->onConnection(function (): void {
            $this->ensureMigrationsTableOnConnection();
        });
    }

    private function ensureMigrationsTableOnConnection(): void
    {
        if (Db::tableExists($this->table)) {
            return;
        }

        $driver = Db::getDriver($this->connection);

        $ddl = match ($driver) {
            'pgsql' => "CREATE TABLE IF NOT EXISTS {$this->table} (
                id SERIAL PRIMARY KEY,
                migration VARCHAR(255) NOT NULL,
                batch INT NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )",
            'sqlite' => "CREATE TABLE IF NOT EXISTS {$this->table} (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                migration VARCHAR(255) NOT NULL,
                batch INT NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )",
            'sqlsrv' => "CREATE TABLE IF NOT EXISTS {$this->table} (
                id INT IDENTITY(1,1) PRIMARY KEY,
                migration VARCHAR(255) NOT NULL,
                batch INT NOT NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )",
            default => "CREATE TABLE IF NOT EXISTS {$this->table} (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                migration VARCHAR(255) NOT NULL,
                batch INT NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        };

        Db::statement($ddl);
    }

    /**
     * 让 Schema 生成与当前连接方言一致的 DDL（迁移文件无需逐个指定 driver）
     */
    protected function applySchemaDriver(): void
    {
        \Kode\Database\Schema\Schema::setDefaultDriver(Db::getDriver($this->connection));
    }

    /**
     * 已执行的迁移（按批次）
     *
     * @return array<string, int> migration => batch
     */
    public function getRan(): array
    {
        return $this->onConnection(function (): array {
            if (!Db::tableExists($this->table)) {
                return [];
            }

            $rows = Db::select("SELECT migration, batch FROM {$this->table} ORDER BY batch, migration");
            $ran = [];
            foreach ($rows as $row) {
                $ran[$row['migration']] = (int) $row['batch'];
            }

            return $ran;
        });
    }

    /**
     * 扫描目录中的迁移文件，按文件名升序排序
     *
     * @return array<int, string> 完整文件路径
     */
    public function getMigrationFiles(): array
    {
        if (!is_dir($this->migrationsPath)) {
            return [];
        }

        $files = glob($this->migrationsPath . '/*.php') ?: [];
        sort($files);
        return $files;
    }

    /**
     * 待执行的迁移文件
     *
     * @return array<int, string>
     */
    public function getPendingFiles(): array
    {
        $ran = array_keys($this->getRan());
        $pending = [];

        foreach ($this->getMigrationFiles() as $file) {
            $name = $this->fileToName($file);
            if (!in_array($name, $ran, true)) {
                $pending[] = $file;
            }
        }

        return $pending;
    }

    /**
     * 从文件名提取迁移名（去掉 .php）
     */
    protected function fileToName(string $file): string
    {
        return basename($file, '.php');
    }

    /**
     * 实例化迁移类
     *
     * 先按「本次 require 新引入了哪些类」找（不依赖命名约定），
     * 找不到再按文件名约定回退查 —— 同一进程里第二次解析同一文件时
     * `require_once` 不会再产出新类（预演后紧接执行、一个测试跑多次），
     * 只靠前者会误报「文件里没有 Migration 子类」。
     */
    protected function resolve(string $file): Migration
    {
        $before = get_declared_classes();
        require_once $file;

        $isMigration = static fn (string $class): bool => is_subclass_of($class, Migration::class)
            && !(new \ReflectionClass($class))->isAbstract();

        $candidate = $this->classNameFor(basename($file, '.php'));
        $fresh = array_values(array_filter(array_diff(get_declared_classes(), $before), $isMigration));

        // 一次 require 带出多个迁移类时，文件名对得上的那个才是本文件的
        $picked = $candidate !== '' && in_array($candidate, $fresh, true)
            ? $candidate
            : ($fresh[0] ?? ($candidate !== '' && $isMigration($candidate) ? $candidate : null));

        if ($picked === null) {
            throw new \RuntimeException("未在迁移文件中找到 Migration 子类: {$file}");
        }

        return new $picked();
    }

    /**
     * 迁移文件名 → 类名（`2026_01_01_000000_add_users_table` → `AddUsersTable`）。
     */
    private function classNameFor(string $name): string
    {
        $words = preg_replace('/^\d+(_\d+)*/', '', $name) ?? $name;
        $words = str_replace(['-', '_'], ' ', trim($words, '_ '));
        $class = preg_replace('/\s+/', '', ucwords($words)) ?? '';

        return preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $class) === 1 ? $class : '';
    }

    /**
     * 运行待执行迁移
     *
     * @param int|null $steps 限制执行步数（null 表示全部）
     * @return array<int, string> 实际执行的迁移名列表
     */
    public function run(?int $steps = null): array
    {
        return $this->runPending($steps, false);
    }

    /**
     * 预演待执行迁移：真跑一遍 up()，结束时整体回滚，不落库、不写迁移记录。
     *
     * 与「把 SQL 打印出来」相比，预演会暴露只有执行才看得出的问题
     * （字段已存在、约束冲突、迁移文件里的分支逻辑）。migrations 表的插入也在同一事务里，
     * 所以预演不会推进批次号。
     *
     * 边界（调用方需要知道）：
     *  - MySQL 的 DDL 会隐式提交，回滚撤销不了建表/改表 —— 这类方言上预演等于真执行；
     *    PostgreSQL / SQLite 的 DDL 可在事务内回滚，才是本方法的预期用法。
     *  - 迁移里跨连接写入（Db::connection('其他库')）、文件与外部调用不受本事务保护。
     *  - migrations 记录表不存在时会被建出来（记账表，事务外建，与迁移内容无关）。
     *
     * @param int|null $steps 限制预演步数（null 表示全部）
     * @return array<int, string> 预演通过的迁移名列表
     */
    public function pretend(?int $steps = null): array
    {
        return $this->runPending($steps, true);
    }

    /**
     * @return array<int, string> 通过（执行或预演）的迁移名列表
     */
    private function runPending(?int $steps, bool $pretend): array
    {
        return $this->onConnection(function () use ($steps, $pretend): array {
            return $this->runPendingOnConnection($steps, $pretend);
        });
    }

    private function runPendingOnConnection(?int $steps, bool $pretend): array
    {
        $this->applySchemaDriver();
        $this->ensureMigrationsTable();

        $pending = $this->getPendingFiles();
        if ($steps !== null) {
            $pending = array_slice($pending, 0, $steps);
        }

        if (empty($pending)) {
            return [];
        }

        $batch = $this->nextBatch();
        $executed = [];

        Db::beginTransaction();
        try {
            foreach ($pending as $file) {
                $migration = $this->resolve($file);
                $migration->up();

                $name = $this->fileToName($file);
                Db::insert(
                    "INSERT INTO {$this->table} (migration, batch) VALUES (?, ?)",
                    [$name, $batch]
                );
                $executed[] = $name;
            }
            if ($pretend) {
                // 不收集 SQL 明细：查询日志有条数上限（见 Db::QUERY_LOG_*），
                // 一份被静默截断的「将要执行的语句」清单比没有清单更危险。
                Db::rollBack();

                return $executed;
            }
            Db::commit();
        } catch (\Throwable $e) {
            Db::rollBack();
            throw $e;
        }

        return $executed;
    }

    /**
     * 回滚最近 $steps 个批次（默认 1 个批次）
     *
     * @return array<int, string> 被回滚的迁移名列表
     */
    public function rollback(int $steps = 1): array
    {
        return $this->onConnection(function () use ($steps): array {
            return $this->rollbackOnConnection($steps);
        });
    }

    private function rollbackOnConnection(int $steps): array
    {
        $this->applySchemaDriver();

        if (!Db::tableExists($this->table)) {
            return [];
        }

        $ran = $this->getRan();
        if (empty($ran)) {
            return [];
        }

        $maxBatch = max($ran);
        $targetBatches = range($maxBatch - $steps + 1, $maxBatch);
        $targetBatches = array_filter($targetBatches, fn($b) => $b >= 1);

        $toRollback = [];
        foreach ($ran as $name => $batch) {
            if (in_array($batch, $targetBatches, true)) {
                $toRollback[$batch][] = $name;
            }
        }

        krsort($toRollback);
        $rolled = [];

        Db::beginTransaction();
        try {
            foreach ($toRollback as $names) {
                foreach ($names as $name) {
                    $file = $this->findFileByName($name);
                    if ($file) {
                        $migration = $this->resolve($file);
                        $migration->down();
                        $rolled[] = $name;
                    }
                    Db::delete("DELETE FROM {$this->table} WHERE migration = ?", [$name]);
                }
            }
            Db::commit();
        } catch (\Throwable $e) {
            Db::rollBack();
            throw $e;
        }

        return $rolled;
    }

    /**
     * 回滚全部迁移
     */
    public function reset(): array
    {
        $all = [];
        while ($batch = $this->rollback(1)) {
            $all = array_merge($all, $batch);
            if (empty($batch)) {
                break;
            }
        }
        return $all;
    }

    protected function nextBatch(): int
    {
        $ran = $this->getRan();
        return empty($ran) ? 1 : (max($ran) + 1);
    }

    protected function findFileByName(string $name): ?string
    {
        foreach ($this->getMigrationFiles() as $file) {
            if ($this->fileToName($file) === $name) {
                return $file;
            }
        }
        return null;
    }
}
