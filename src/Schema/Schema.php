<?php

declare(strict_types=1);

namespace Kode\Database\Schema;

/**
 * 表结构构建器
 */
class Schema
{
    protected string $table;
    protected array $columns = [];
    protected array $indexes = [];
    protected array $foreignKeys = [];

    public function __construct(string $table)
    {
        $this->table = $table;
    }

    /**
     * 创建表
     */
    public static function create(string $table, callable $callback): string
    {
        $schema = new self($table);
        $callback($schema);
        return $schema->toSql();
    }

    /**
     * 修改表
     */
    public static function table(string $table, callable $callback): string
    {
        $schema = new self($table);
        $callback($schema);
        return $schema->toAlterSql();
    }

    /**
     * 删除表
     */
    public static function drop(string $table): string
    {
        return "DROP TABLE IF EXISTS {$table}";
    }

    /**
     * 添加字段
     */
    public function column(string $name, string $type, array $options = []): self
    {
        $this->columns[] = new Column($name, $type, $options);
        return $this;
    }

    /**
     * 主键
     */
    public function primaryKey(array|string $columns): self
    {
        $columns = is_array($columns) ? $columns : [$columns];
        $this->indexes[] = [
            'type' => 'PRIMARY KEY',
            'columns' => $columns,
        ];
        return $this;
    }

    /**
     * 唯一索引
     */
    public function uniqueKey(array|string $columns, ?string $name = null): self
    {
        $columns = is_array($columns) ? $columns : [$columns];
        $this->indexes[] = [
            'type' => 'UNIQUE',
            'name' => $name,
            'columns' => $columns,
        ];
        return $this;
    }

    /**
     * 普通索引
     */
    public function index(array|string $columns, ?string $name = null): self
    {
        $columns = is_array($columns) ? $columns : [$columns];
        $this->indexes[] = [
            'type' => 'INDEX',
            'name' => $name,
            'columns' => $columns,
        ];
        return $this;
    }

    /**
     * 外键
     */
    public function foreign(string $column): ForeignKey
    {
        $foreignKey = new ForeignKey($column);
        $this->foreignKeys[] = $foreignKey;
        return $foreignKey;
    }

    /**
     * id 字段（自增主键）
     */
    public function id(?string $name = 'id'): self
    {
        $this->columns[] = new Column($name, 'bigint', [
            'unsigned' => true,
            'auto_increment' => true,
        ]);
        $this->primaryKey($name);
        return $this;
    }

    /**
     * 递增字段
     */
    public function increments(string $name): self
    {
        return $this->integer($name, true);
    }

    /**
     * 字符串字段
     */
    public function string(string $name, int $length = 255): self
    {
        $this->columns[] = new Column($name, 'varchar', ['length' => $length]);
        return $this;
    }

    /**
     * 文本字段
     */
    public function text(string $name): self
    {
        $this->columns[] = new Column($name, 'text');
        return $this;
    }

    /**
     * 整数字段
     */
    public function integer(string $name, bool $increment = false): self
    {
        $type = $increment ? 'int' : 'integer';
        $options = $increment ? ['auto_increment' => true, 'unsigned' => true] : [];
        $this->columns[] = new Column($name, $type, $options);

        if ($increment) {
            $this->primaryKey($name);
        }

        return $this;
    }

    /**
     * 长整数字段
     */
    public function bigInteger(string $name): self
    {
        $this->columns[] = new Column($name, 'bigint');
        return $this;
    }

    /**
     * 浮点数字段
     */
    public function float(string $name, int $precision = 10, int $scale = 2): self
    {
        $this->columns[] = new Column($name, 'float', ['precision' => $precision, 'scale' => $scale]);
        return $this;
    }

    /**
     * 双精度浮点数字段
     */
    public function double(string $name): self
    {
        $this->columns[] = new Column($name, 'double');
        return $this;
    }

    /**
     * 小数字段
     */
    public function decimal(string $name, int $precision = 10, int $scale = 2): self
    {
        $this->columns[] = new Column($name, 'decimal', ['precision' => $precision, 'scale' => $scale]);
        return $this;
    }

    /**
     * 布尔字段
     */
    public function boolean(string $name): self
    {
        $this->columns[] = new Column($name, 'tinyint', ['length' => 1, 'default' => 0]);
        return $this;
    }

    /**
     * 日期字段
     */
    public function date(string $name): self
    {
        $this->columns[] = new Column($name, 'date');
        return $this;
    }

    /**
     * 时间戳字段
     */
    public function timestamp(string $name): self
    {
        $this->columns[] = new Column($name, 'timestamp');
        return $this;
    }

    /**
     * 创建时间戳
     */
    public function timestamps(): self
    {
        $this->columns[] = new Column('created_at', 'timestamp', ['nullable' => true]);
        $this->columns[] = new Column('updated_at', 'timestamp', ['nullable' => true]);
        return $this;
    }

    /**
     * 软删除时间戳
     */
    public function softDeletes(): self
    {
        $this->columns[] = new Column('deleted_at', 'timestamp', ['nullable' => true]);
        return $this;
    }

    /**
     * JSON 字段
     */
    public function json(string $name): self
    {
        $this->columns[] = new Column($name, 'json');
        return $this;
    }

    /**
     * 生成 SQL
     */
    public function toSql(): string
    {
        $parts = ["CREATE TABLE {$this->table} ("];

        $columnDefs = [];
        foreach ($this->columns as $column) {
            $columnDefs[] = '  ' . $column->toSql();
        }

        foreach ($this->indexes as $index) {
            $columns = implode(', ', $index['columns']);
            if (isset($index['name'])) {
                $columnDefs[] = "  {$index['type']} {$index['name']} ({$columns})";
            } else {
                $columnDefs[] = "  {$index['type']} ({$columns})";
            }
        }

        foreach ($this->foreignKeys as $fk) {
            $columnDefs[] = '  ' . $fk->toSql();
        }

        $parts[] = implode(",\n", $columnDefs);
        $parts[] = ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';

        return implode("\n", $parts);
    }

    /**
     * 生成修改表 SQL
     */
    public function toAlterSql(): string
    {
        $parts = ["ALTER TABLE {$this->table}"];

        foreach ($this->columns as $column) {
            $parts[] = '  ADD ' . $column->toSql() . ',';
        }

        foreach ($this->indexes as $index) {
            $columns = implode(', ', $index['columns']);
            $name = $index['name'] ?? '';
            $parts[] = "  ADD {$index['type']} {$name} ({$columns}),";
        }

        foreach ($this->foreignKeys as $fk) {
            $parts[] = '  ADD ' . $fk->toSql() . ',';
        }

        $sql = implode("\n", $parts);
        return rtrim($sql, ',');
    }
}
