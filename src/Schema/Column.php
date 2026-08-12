<?php

declare(strict_types=1);

namespace Kode\Database\Schema;

/**
 * 字段定义
 *
 * 类型与自增语法随数据库方言（driver）变化，故 Column 需感知 driver 以生成正确的 DDL。
 */
class Column
{
    protected string $name;
    protected string $type;
    protected array $options = [];

    /** @var string 数据库方言：mysql / pgsql / sqlite / sqlsrv / oracle */
    protected string $driver = 'mysql';

    public function __construct(string $name, string $type, array $options = [])
    {
        $this->name = $name;
        $this->type = $type;
        $this->options = $options;
    }

    public function setDriver(string $driver): static
    {
        $this->driver = strtolower($driver);
        return $this;
    }

    public function toSql(): string
    {
        $sql = "{$this->name} {$this->buildType()}";

        // UNSIGNED 仅在 MySQL 语义下有效
        if (($this->options['unsigned'] ?? false) && $this->driver === 'mysql') {
            $sql .= ' UNSIGNED';
        }

        if (($this->options['not_null'] ?? false) || ($this->options['primary_key'] ?? false)) {
            $sql .= ' NOT NULL';
        }

        // 自增语法：MySQL 用 AUTO_INCREMENT；SQLite 用 AUTOINCREMENT；
        // SQL Server 用 IDENTITY(1,1)；PostgreSQL 在 buildType() 中已转为 SERIAL/BIGSERIAL。
        if ($this->options['auto_increment'] ?? false) {
            if ($this->driver === 'mysql') {
                $sql .= ' AUTO_INCREMENT';
            } elseif ($this->driver === 'sqlite') {
                $sql .= ' AUTOINCREMENT';
            } elseif ($this->driver === 'sqlsrv') {
                $sql .= ' IDENTITY(1,1)';
            }
        }

        if ($this->options['primary_key'] ?? false) {
            $sql .= ' PRIMARY KEY';
        }

        if (isset($this->options['default'])) {
            $default = $this->options['default'];
            if ($default === null) {
                $sql .= ' DEFAULT NULL';
            } elseif (is_string($default)) {
                $sql .= " DEFAULT '{$default}'";
            } else {
                $sql .= " DEFAULT {$default}";
            }
        }

        // 列注释：SQLite / PostgreSQL 不支持内联列 COMMENT
        if ($this->options['comment'] ?? false) {
            if (in_array($this->driver, ['mysql', 'sqlsrv', 'oracle'], true)) {
                $sql .= " COMMENT '{$this->options['comment']}'";
            }
        }

        return $sql;
    }

    protected function buildType(): string
    {
        // 自增在 PostgreSQL 下通过类型转为 SERIAL / BIGSERIAL
        if (($this->options['auto_increment'] ?? false) && $this->driver === 'pgsql') {
            return $this->type === 'bigint' ? 'BIGSERIAL' : 'SERIAL';
        }

        $type = match ($this->type) {
            'bigint' => 'bigint',
            'int', 'integer' => 'int',
            'smallint' => 'smallint',
            'mediumint' => 'mediumint',
            'tinyint' => 'tinyint',
            'varchar' => 'varchar(' . ($this->options['length'] ?? 255) . ')',
            'char' => 'char(' . ($this->options['length'] ?? 255) . ')',
            'text' => 'text',
            'mediumtext' => 'mediumtext',
            'longtext' => 'longtext',
            'float' => 'float(' . ($this->options['precision'] ?? 10) . ',' . ($this->options['scale'] ?? 2) . ')',
            'double' => 'double',
            'decimal' => 'decimal(' . ($this->options['precision'] ?? 10) . ',' . ($this->options['scale'] ?? 2) . ')',
            'date' => 'date',
            'datetime' => 'datetime',
            'timestamp' => 'timestamp',
            'time' => 'time',
            'year' => 'year',
            'blob' => 'blob',
            'json' => $this->driver === 'postgres' || $this->driver === 'pgsql' ? 'jsonb' : 'json',
            'boolean' => match ($this->driver) {
                'pgsql' => 'boolean',
                'sqlite' => 'integer',
                'sqlsrv' => 'bit',
                'oracle' => 'number(1)',
                default => 'tinyint(1)',
            },
            default => $this->type,
        };

        // SQLite 不支持 mediumtext / longtext 等，统一降级为 text
        if ($this->driver === 'sqlite' && in_array($type, ['mediumtext', 'longtext', 'mediumint'], true)) {
            return 'text';
        }

        return $type;
    }

    /**
     * 是否为删除操作
     */
    public function isDrop(): bool
    {
        return $this->options['drop'] ?? false;
    }

    /**
     * 是否为修改操作
     */
    public function isModify(): bool
    {
        return $this->options['modify'] ?? false;
    }

    /**
     * 获取字段名
     */
    public function getName(): string
    {
        return $this->name;
    }

    /**
     * 设置默认值
     */
    public function setDefault(mixed $value): void
    {
        $this->options['default'] = $value;
    }

    /**
     * 设置 nullable
     */
    public function setNullable(bool $nullable = true): void
    {
        if ($nullable) {
            $this->options['not_null'] = false;
        }
    }

    /**
     * 设置无符号
     */
    public function setUnsigned(bool $unsigned = true): void
    {
        $this->options['unsigned'] = $unsigned;
    }

    /**
     * 设置注释
     */
    public function setComment(string $comment): void
    {
        $this->options['comment'] = $comment;
    }

    /**
     * 设置 AFTER
     */
    public function setAfter(string $column): void
    {
        $this->options['after'] = $column;
    }

    /**
     * 获取类型
     */
    public function getType(): string
    {
        return $this->type;
    }

    /**
     * 获取选项
     */
    public function getOptions(): array
    {
        return $this->options;
    }
}
