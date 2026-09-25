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
        // `nullable` 与 `not_null` 说的是同一件事，但两个键的历史上传者都有（Schema 的
        // 类型助手传 `nullable`，addColumn 的调用方按直觉传 `not_null` 或 `nullable`），
        // 而 toSql() 只读 `not_null` —— 于是 `['nullable' => false]` 被当成死配置，
        // 作者以为收紧了，DDL 里那个列仍然是可空的。进门先归一成一个键。
        if (array_key_exists('nullable', $options) && !array_key_exists('not_null', $options)) {
            $options['not_null'] = !($options['nullable'] ?? false);
        }
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
        // SQLite 自增列必须是严格形态 `INTEGER PRIMARY KEY AUTOINCREMENT`
        //（类型名须为 INTEGER 且顺序固定），此处整体特判，避免通用拼接产出非法 DDL。
        if (($this->options['auto_increment'] ?? false) && $this->driver === 'sqlite') {
            return "{$this->name} INTEGER PRIMARY KEY AUTOINCREMENT";
        }

        $sql = "{$this->name} {$this->buildType()}";

        // UNSIGNED 仅在 MySQL 语义下有效
        if (($this->options['unsigned'] ?? false) && $this->driver === 'mysql') {
            $sql .= ' UNSIGNED';
        }

        if (($this->options['not_null'] ?? false) || ($this->options['primary_key'] ?? false)) {
            $sql .= ' NOT NULL';
        }

        // 内联唯一约束（单字段唯一）。多字段唯一请用 Schema::uniqueKey()
        if ($this->options['unique'] ?? false) {
            $sql .= ' UNIQUE';
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
            // `string` 是 `addColumn($name, $type)` 那条路上调用方会写的词（`Schema::table()` 的
            // @example 自己就这么写），而助手方法 `string()` 内部传的是 `varchar`。
            // 只认后者时，前者会原样落进 DDL 变成 `ADD x string` —— 一句语法错误的建列语句。
            'string' => 'varchar(' . ($this->options['length'] ?? 255) . ')',
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
     *
     * 必须两个方向都落：`setNullable(false)` 是「这个列不许为空」的唯一收紧入口。
     * 此前它只在 true 分支写值，false 是空操作，于是「收紧」在整个建表器里没有任何
     * API 能表达（除主键），NOT NULL 的列全要靠手写 DDL 才建得出来。
     */
    public function setNullable(bool $nullable = true): void
    {
        $this->options['not_null'] = !$nullable;
    }

    /**
     * 设置无符号
     */
    public function setUnsigned(bool $unsigned = true): void
    {
        $this->options['unsigned'] = $unsigned;
    }

    /**
     * 设置内联唯一约束
     */
    public function setUnique(bool $unique = true): void
    {
        $this->options['unique'] = $unique;
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
