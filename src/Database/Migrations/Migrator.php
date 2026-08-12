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
     * 确保 migrations 表存在
     */
    public function ensureMigrationsTable(): void
    {
        if (Db::tableExists($this->table)) {
            return;
        }

        Db::statement(
            "CREATE TABLE IF NOT EXISTS {$this->table} (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                migration VARCHAR(255) NOT NULL,
                batch INT NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
    }

    /**
     * 已执行的迁移（按批次）
     *
     * @return array<string, int> migration => batch
     */
    public function getRan(): array
    {
        if (!Db::tableExists($this->table)) {
            return [];
        }

        $rows = Db::select("SELECT migration, batch FROM {$this->table} ORDER BY batch, migration");
        $ran = [];
        foreach ($rows as $row) {
            $ran[$row['migration']] = (int) $row['batch'];
        }
        return $ran;
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
     */
    protected function resolve(string $file): Migration
    {
        $before = get_declared_classes();
        require_once $file;
        $after = get_declared_classes();

        foreach (array_diff($after, $before) as $class) {
            if (is_subclass_of($class, Migration::class) && !(new \ReflectionClass($class))->isAbstract()) {
                return new $class();
            }
        }

        throw new \RuntimeException("未在迁移文件中找到 Migration 子类: {$file}");
    }

    /**
     * 运行待执行迁移
     *
     * @param int|null $steps 限制执行步数（null 表示全部）
     * @return array<int, string> 实际执行的迁移名列表
     */
    public function run(?int $steps = null): array
    {
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
