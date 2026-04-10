<?php

declare(strict_types=1);

namespace Kode\Database\Schema;

/**
 * 字段定义
 */
class Column
{
    protected string $name;
    protected string $type;
    protected array $options = [];

    public function __construct(string $name, string $type, array $options = [])
    {
        $this->name = $name;
        $this->type = $type;
        $this->options = $options;
    }

    public function toSql(): string
    {
        $sql = "{$this->name} {$this->buildType()}";

        if ($this->options['unsigned'] ?? false) {
            $sql .= ' UNSIGNED';
        }

        if ($this->options['not_null'] ?? false || $this->options['primary_key'] ?? false) {
            $sql .= ' NOT NULL';
        }

        if ($this->options['auto_increment'] ?? false) {
            $sql .= ' AUTO_INCREMENT';
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

        if ($this->options['comment'] ?? false) {
            $sql .= " COMMENT '{$this->options['comment']}'";
        }

        return $sql;
    }

    protected function buildType(): string
    {
        return match ($this->type) {
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
            'boolean' => 'tinyint(1)',
            'json' => 'json',
            'blob' => 'blob',
            default => $this->type,
        };
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
