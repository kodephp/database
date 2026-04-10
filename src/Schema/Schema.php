<?php

declare(strict_types=1);

namespace Kode\Database\Schema;

/**
 * 表结构构建器
 * 支持创建表、修改表、删除表、字段定义、索引、外键
 */
class Schema
{
    /** @var string 表名 */
    protected string $table;

    /** @var array 字段列表 */
    protected array $columns = [];

    /** @var array 索引列表 */
    protected array $indexes = [];

    /** @var array 外键列表 */
    protected array $foreignKeys = [];

    /** @var array 表选项 */
    protected array $options = [];

    /** @var string 表引擎 */
    protected string $engine = 'InnoDB';

    /** @var string 字符集 */
    protected string $charset = 'utf8mb4';

    /** @var string 排序规则 */
    protected string $collation = 'utf8mb4_unicode_ci';

    public function __construct(string $table)
    {
        $this->table = $table;
    }

    /**
     * 创建表
     *
     * @param string $table 表名
     * @param callable $callback 回调
     * @return string SQL 语句
     * @example Schema::create('users', function ($t) { $t->id(); $t->string('name'); })
     */
    public static function create(string $table, callable $callback): string
    {
        $schema = new self($table);
        $callback($schema);
        return $schema->toSql();
    }

    /**
     * 修改表
     *
     * @param string $table 表名
     * @param callable $callback 回调
     * @return string SQL 语句
     * @example Schema::table('users', function ($t) { $t->addColumn('phone', 'string', ['length' => 11]); })
     */
    public static function table(string $table, callable $callback): string
    {
        $schema = new self($table);
        $callback($schema);
        return $schema->toAlterSql();
    }

    /**
     * 删除表
     *
     * @param string $table 表名
     * @return string SQL 语句
     */
    public static function drop(string $table): string
    {
        return "DROP TABLE IF EXISTS {$table}";
    }

    /**
     * 判断表是否存在
     *
     * @param string $table 表名
     * @return string SQL 语句
     */
    public static function hasTable(string $table): string
    {
        return "SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '{$table}'";
    }

    /**
     * 判断字段是否存在
     *
     * @param string $table 表名
     * @param string $column 字段名
     * @return string SQL 语句
     */
    public static function hasColumn(string $table, string $column): string
    {
        return "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '{$table}' AND COLUMN_NAME = '{$column}'";
    }

    /**
     * 设置表引擎
     *
     * @param string $engine 引擎名 (InnoDB, MyISAM)
     * @return $this
     */
    public function engine(string $engine): static
    {
        $this->engine = $engine;
        return $this;
    }

    /**
     * 设置字符集
     *
     * @param string $charset 字符集
     * @return $this
     */
    public function charset(string $charset): static
    {
        $this->charset = $charset;
        return $this;
    }

    /**
     * 设置排序规则
     *
     * @param string $collation 排序规则
     * @return $this
     */
    public function collation(string $collation): static
    {
        $this->collation = $collation;
        return $this;
    }

    /**
     * 设置表注释
     *
     * @param string $comment 注释
     * @return $this
     */
    public function comment(string $comment): static
    {
        $this->options['comment'] = $comment;
        return $this;
    }

    /**
     * 设置自增初始值
     *
     * @param int $value 初始值
     * @return $this
     */
    public function autoIncrement(int $value): static
    {
        $this->options['auto_increment'] = $value;
        return $this;
    }

    /**
     * 添加字段
     *
     * @param string $name 字段名
     * @param string $type 类型
     * @param array $options 选项
     * @return $this
     */
    public function column(string $name, string $type, array $options = []): static
    {
        $this->columns[] = new Column($name, $type, $options);
        return $this;
    }

    /**
     * 添加字段（别名）
     *
     * @param string $name 字段名
     * @param string $type 类型
     * @param array $options 选项
     * @return $this
     */
    public function addColumn(string $name, string $type, array $options = []): static
    {
        return $this->column($name, $type, $options);
    }

    /**
     * 修改字段
     *
     * @param string $name 字段名
     * @param array $options 选项
     * @return $this
     */
    public function modifyColumn(string $name, array $options): static
    {
        $this->columns[] = new Column($name, $options['type'] ?? 'varchar', $options);
        return $this;
    }

    /**
     * 删除字段
     *
     * @param string $name 字段名
     * @return $this
     */
    public function dropColumn(string $name): static
    {
        $this->columns[] = new Column($name, '', ['drop' => true]);
        return $this;
    }

    /**
     * 主键
     *
     * @param array|string $columns 字段名
     * @return $this
     */
    public function primaryKey(array|string $columns): static
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
     *
     * @param array|string $columns 字段名
     * @param string|null $name 索引名
     * @return $this
     */
    public function uniqueKey(array|string $columns, ?string $name = null): static
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
     *
     * @param array|string $columns 字段名
     * @param string|null $name 索引名
     * @return $this
     */
    public function index(array|string $columns, ?string $name = null): static
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
     *
     * @param string $column 字段名
     * @return ForeignKey
     */
    public function foreign(string $column): ForeignKey
    {
        $foreignKey = new ForeignKey($column);
        $this->foreignKeys[] = $foreignKey;
        return $foreignKey;
    }

    /**
     * id 字段（自增主键）
     *
     * @param string|null $name 字段名
     * @return $this
     */
    public function id(?string $name = 'id'): static
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
     *
     * @param string $name 字段名
     * @return $this
     */
    public function increments(string $name): static
    {
        return $this->integer($name, true);
    }

    /**
     * 字符串字段
     *
     * @param string $name 字段名
     * @param int $length 长度
     * @return $this
     */
    public function string(string $name, int $length = 255): static
    {
        $this->columns[] = new Column($name, 'varchar', ['length' => $length]);
        return $this;
    }

    /**
     * 固定长度字符串
     *
     * @param string $name 字段名
     * @param int $length 长度
     * @return $this
     */
    public function char(string $name, int $length = 10): static
    {
        $this->columns[] = new Column($name, 'char', ['length' => $length]);
        return $this;
    }

    /**
     * 文本字段
     *
     * @param string $name 字段名
     * @return $this
     */
    public function text(string $name): static
    {
        $this->columns[] = new Column($name, 'text');
        return $this;
    }

    /**
     * 中等文本
     *
     * @param string $name 字段名
     * @return $this
     */
    public function mediumText(string $name): static
    {
        $this->columns[] = new Column($name, 'mediumtext');
        return $this;
    }

    /**
     * 长文本
     *
     * @param string $name 字段名
     * @return $this
     */
    public function longText(string $name): static
    {
        $this->columns[] = new Column($name, 'longtext');
        return $this;
    }

    /**
     * 整数字段
     *
     * @param string $name 字段名
     * @param bool $increment 是否自增
     * @return $this
     */
    public function integer(string $name, bool $increment = false): static
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
     * tinyint 字段
     *
     * @param string $name 字段名
     * @param int $length 长度
     * @return $this
     */
    public function tinyInteger(string $name, int $length = 4): static
    {
        $this->columns[] = new Column($name, 'tinyint', ['length' => $length]);
        return $this;
    }

    /**
     * smallint 字段
     *
     * @param string $name 字段名
     * @return $this
     */
    public function smallInteger(string $name): static
    {
        $this->columns[] = new Column($name, 'smallint');
        return $this;
    }

    /**
     * mediumint 字段
     *
     * @param string $name 字段名
     * @return $this
     */
    public function mediumInteger(string $name): static
    {
        $this->columns[] = new Column($name, 'mediumint');
        return $this;
    }

    /**
     * 长整数字段
     *
     * @param string $name 字段名
     * @return $this
     */
    public function bigInteger(string $name): static
    {
        $this->columns[] = new Column($name, 'bigint');
        return $this;
    }

    /**
     * 无符号整数字段
     *
     * @param string $name 字段名
     * @param bool $increment 是否自增
     * @return $this
     */
    public function unsignedInteger(string $name, bool $increment = false): static
    {
        $type = $increment ? 'int' : 'integer';
        $options = $increment ? ['auto_increment' => true, 'unsigned' => true] : ['unsigned' => true];
        $this->columns[] = new Column($name, $type, $options);

        if ($increment) {
            $this->primaryKey($name);
        }

        return $this;
    }

    /**
     * 浮点数字段
     *
     * @param string $name 字段名
     * @param int $precision 精度
     * @param int $scale 范围
     * @return $this
     */
    public function float(string $name, int $precision = 10, int $scale = 2): static
    {
        $this->columns[] = new Column($name, 'float', ['precision' => $precision, 'scale' => $scale]);
        return $this;
    }

    /**
     * 双精度浮点数字段
     *
     * @param string $name 字段名
     * @return $this
     */
    public function double(string $name): static
    {
        $this->columns[] = new Column($name, 'double');
        return $this;
    }

    /**
     * 小数字段
     *
     * @param string $name 字段名
     * @param int $precision 精度
     * @param int $scale 范围
     * @return $this
     */
    public function decimal(string $name, int $precision = 10, int $scale = 2): static
    {
        $this->columns[] = new Column($name, 'decimal', ['precision' => $precision, 'scale' => $scale]);
        return $this;
    }

    /**
     * 布尔字段
     *
     * @param string $name 字段名
     * @return $this
     */
    public function boolean(string $name): static
    {
        $this->columns[] = new Column($name, 'tinyint', ['length' => 1, 'default' => 0]);
        return $this;
    }

    /**
     * 日期字段
     *
     * @param string $name 字段名
     * @return $this
     */
    public function date(string $name): static
    {
        $this->columns[] = new Column($name, 'date');
        return $this;
    }

    /**
     * 时间字段
     *
     * @param string $name 字段名
     * @return $this
     */
    public function time(string $name): static
    {
        $this->columns[] = new Column($name, 'time');
        return $this;
    }

    /**
     * 日期时间字段
     *
     * @param string $name 字段名
     * @return $this
     */
    public function dateTime(string $name): static
    {
        $this->columns[] = new Column($name, 'datetime');
        return $this;
    }

    /**
     * 时间戳字段
     *
     * @param string $name 字段名
     * @return $this
     */
    public function timestamp(string $name): static
    {
        $this->columns[] = new Column($name, 'timestamp');
        return $this;
    }

    /**
     * 年份字段
     *
     * @param string $name 字段名
     * @return $this
     */
    public function year(string $name): static
    {
        $this->columns[] = new Column($name, 'year');
        return $this;
    }

    /**
     * 创建时间戳
     *
     * @return $this
     */
    public function timestamps(): static
    {
        $this->columns[] = new Column('created_at', 'timestamp', ['nullable' => true]);
        $this->columns[] = new Column('updated_at', 'timestamp', ['nullable' => true]);
        return $this;
    }

    /**
     * 软删除时间戳
     *
     * @return $this
     */
    public function softDeletes(): static
    {
        $this->columns[] = new Column('deleted_at', 'timestamp', ['nullable' => true]);
        return $this;
    }

    /**
     * 记住令牌
     *
     * @return $this
     */
    public function rememberToken(): static
    {
        $this->columns[] = new Column('remember_token', 'varchar', ['length' => 100, 'nullable' => true]);
        return $this;
    }

    /**
     * IP 地址字段
     *
     * @param string $name 字段名
     * @return $this
     */
    public function ipAddress(string $name): static
    {
        $this->columns[] = new Column($name, 'varchar', ['length' => 45]);
        return $this;
    }

    /**
     * MAC 地址字段
     *
     * @param string $name 字段名
     * @return $this
     */
    public function macAddress(string $name): static
    {
        $this->columns[] = new Column($name, 'char', ['length' => 17]);
        return $this;
    }

    /**
     * UUID 字段
     *
     * @param string $name 字段名
     * @return $this
     */
    public function uuid(string $name): static
    {
        $this->columns[] = new Column($name, 'char', ['length' => 36]);
        return $this;
    }

    /**
     * JSON 字段
     *
     * @param string $name 字段名
     * @return $this
     */
    public function json(string $name): static
    {
        $this->columns[] = new Column($name, 'json');
        return $this;
    }

    /**
     * 二进制字段
     *
     * @param string $name 字段名
     * @param int $length 长度
     * @return $this
     */
    public function binary(string $name, int $length = 255): static
    {
        $this->columns[] = new Column($name, 'binary', ['length' => $length]);
        return $this;
    }

    /**
     * 枚举字段
     *
     * @param string $name 字段名
     * @param array $values 枚举值
     * @return $this
     */
    public function enum(string $name, array $values): static
    {
        $allowed = implode(',', array_map(fn($v) => "'{$v}'", $values));
        $this->columns[] = new Column($name, "enum({$allowed})", ['nullable' => true]);
        return $this;
    }

    /**
     * 设置字段默认值
     *
     * @param mixed $value 默认值
     * @return $this
     */
    public function default(mixed $value): static
    {
        if (empty($this->columns)) {
            return $this;
        }

        $lastColumn = end($this->columns);
        if ($lastColumn instanceof Column) {
            $lastColumn->setDefault($value);
        }
        return $this;
    }

    /**
     * 设置字段为 nullable
     *
     * @return $this
     */
    public function nullable(): static
    {
        if (empty($this->columns)) {
            return $this;
        }

        $lastColumn = end($this->columns);
        if ($lastColumn instanceof Column) {
            $lastColumn->setNullable(true);
        }
        return $this;
    }

    /**
     * 设置字段为无符号
     *
     * @return $this
     */
    public function unsigned(): static
    {
        if (empty($this->columns)) {
            return $this;
        }

        $lastColumn = end($this->columns);
        if ($lastColumn instanceof Column) {
            $lastColumn->setUnsigned(true);
        }
        return $this;
    }

    /**
     * 设置字段注释
     *
     * @param string $comment 注释
     * @return $this
     */
    public function columnComment(string $comment): static
    {
        if (empty($this->columns)) {
            return $this;
        }

        $lastColumn = end($this->columns);
        if ($lastColumn instanceof Column) {
            $lastColumn->setComment($comment);
        }
        return $this;
    }

    /**
     * 设置字段 AFTER
     *
     * @param string $afterColumn 字段名
     * @return $this
     */
    public function after(string $afterColumn): static
    {
        if (empty($this->columns)) {
            return $this;
        }

        $lastColumn = end($this->columns);
        if ($lastColumn instanceof Column) {
            $lastColumn->setAfter($afterColumn);
        }
        return $this;
    }

    /**
     * 生成 SQL
     *
     * @return string SQL 语句
     */
    public function toSql(): string
    {
        $parts = ["CREATE TABLE {$this->table} ("];

        $columnDefs = [];
        foreach ($this->columns as $column) {
            $def = $column->toSql();
            if (!empty($def)) {
                $columnDefs[] = '  ' . $def;
            }
        }

        foreach ($this->indexes as $index) {
            $columns = implode(', ', $index['columns']);
            if (isset($index['name']) && !empty($index['name'])) {
                $columnDefs[] = "  {$index['type']} {$index['name']} ({$columns})";
            } else {
                $columnDefs[] = "  {$index['type']} ({$columns})";
            }
        }

        foreach ($this->foreignKeys as $fk) {
            $def = $fk->toSql();
            if (!empty($def)) {
                $columnDefs[] = '  ' . $def;
            }
        }

        $parts[] = implode(",\n", $columnDefs);
        $parts[] = ') ENGINE=' . $this->engine;
        $parts[] = ' DEFAULT CHARSET=' . $this->charset;
        $parts[] = ' COLLATE=' . $this->collation;

        if (isset($this->options['auto_increment'])) {
            $parts[] = ' AUTO_INCREMENT=' . $this->options['auto_increment'];
        }

        if (isset($this->options['comment'])) {
            $parts[] = " COMMENT='" . addslashes($this->options['comment']) . "'";
        }

        return implode("\n", $parts);
    }

    /**
     * 生成修改表 SQL
     *
     * @return string SQL 语句
     */
    public function toAlterSql(): string
    {
        $parts = ["ALTER TABLE {$this->table}"];

        foreach ($this->columns as $column) {
            $def = $column->toSql();
            if (empty($def)) {
                continue;
            }

            if ($column->isDrop()) {
                $parts[] = '  DROP COLUMN ' . $column->getName() . ',';
            } elseif ($column->isModify()) {
                $parts[] = '  MODIFY ' . $def . ',';
            } else {
                $parts[] = '  ADD ' . $def . ',';
            }
        }

        foreach ($this->indexes as $index) {
            $columns = implode(', ', $index['columns']);
            $name = $index['name'] ?? '';
            if ($index['type'] === 'PRIMARY KEY') {
                $parts[] = "  ADD PRIMARY KEY ({$columns}),";
            } elseif ($index['type'] === 'UNIQUE') {
                $parts[] = "  ADD UNIQUE {$name} ({$columns}),";
            } else {
                $parts[] = "  ADD INDEX {$name} ({$columns}),";
            }
        }

        foreach ($this->foreignKeys as $fk) {
            $def = $fk->toSql();
            if (!empty($def)) {
                $parts[] = '  ADD ' . $def . ',';
            }
        }

        $sql = implode("\n", $parts);
        return rtrim($sql, ',');
    }
}
