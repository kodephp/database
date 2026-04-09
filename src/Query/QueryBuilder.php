<?php

declare(strict_types=1);

namespace Kode\Database\Query;

use Kode\Database\Exception\QueryException;

/**
 * 查询构建器
 * 兼容 Laravel/ThinkPHP 查询语法
 */
class QueryBuilder
{
    protected string $table = '';
    protected array $columns = ['*'];
    protected array $wheres = [];
    protected array $bindings = [];
    protected ?int $limit = null;
    protected ?int $offset = null;
    protected string $orderBy = '';
    protected string $orderDirection = 'ASC';
    protected ?string $groupBy = null;
    protected ?string $having = null;
    protected ?string $primaryKey = 'id';
    protected array $joins = [];
    protected array $unionQueries = [];
    protected ?string $lockFor = null;
    protected string $tableAlias = '';
    protected bool $isDistinct = false;

    public function __construct(protected mixed $connection)
    {
    }

    /**
     * 设置表
     */
    public function table(string $table): static
    {
        $this->table = $table;
        return $this;
    }

    /**
     * from 别名
     */
    public function from(string $table): static
    {
        $this->table = $table;
        return $this;
    }

    /**
     * 获取当前表名
     */
    public function getTable(): string
    {
        return $this->table;
    }

    /**
     * 设置查询列
     * Laravel/ThinkPHP 风格: Db::table('users')->field('name,email')->get()
     * ThinkPHP 风格别名: Db::table('users')->field('name,email')->select()
     */
    public function select(array|string ...$columns): static
    {
        if (count($columns) === 1 && is_string($columns[0]) && strpos($columns[0], ',') !== false) {
            $columns = array_map('trim', explode(',', $columns[0]));
        }
        $this->columns = $columns;
        return $this;
    }

    /**
     * field - 选择字段（ThinkPHP 风格）
     * 支持字符串和数组两种方式
     *
     * @param array|string $fields 字段名
     * @return static
     * @example Db::table('users')->field('name,email')->get()
     * @example Db::table('users')->field(['name', 'email'])->get()
     */
    public function field(array|string $fields): static
    {
        if (is_array($fields)) {
            return $this->select(...$fields);
        }

        $columns = array_map('trim', explode(',', $fields));
        return $this->select(...$columns);
    }

    /**
     * order - 排序（ThinkPHP 风格）
     *
     * @param string|array $order 排序字段
     * @param string $direction 排序方向
     * @return static
     * @example Db::table('users')->order('id desc')->get()
     * @example Db::table('users')->order('id', 'desc')->get()
     */
    public function order(string|array $order, string $direction = 'ASC'): static
    {
        if (is_array($order)) {
            foreach ($order as $field => $dir) {
                $this->orderBy($field, is_int($field) ? $direction : $dir);
            }
            return $this;
        }

        if (stripos($order, ' ') !== false) {
            $parts = array_map('trim', explode(' ', $order));
            $field = $parts[0];
            $dir = $parts[1] ?? 'ASC';
            return $this->orderBy($field, strtoupper($dir) === 'DESC' ? 'DESC' : 'ASC');
        }

        return $this->orderBy($order, strtoupper($direction) === 'DESC' ? 'DESC' : 'ASC');
    }

    /**
     * page - 分页（ThinkPHP 风格）
     *
     * @param int $page 页码
     * @param int $limit 每页数量
     * @return static
     * @example Db::table('users')->page(1, 15)->get()
     */
    public function page(int $page, int $limit = 15): static
    {
        $this->limit($limit);
        $this->offset(($page - 1) * $limit);
        return $this;
    }

    /**
     * alias - 别名（ThinkPHP 风格）
     *
     * @param string $alias 别名
     * @return static
     */
    public function alias(string $alias): static
    {
        $this->tableAlias = $this->table . ' AS ' . $alias;
        return $this;
    }

    /**
     * group - 分组（ThinkPHP 风格）
     *
     * @param string|array $group 分组字段
     * @return static
     * @example Db::table('users')->group('status')->get()
     */
    public function group(string|array $group): static
    {
        if (is_array($group)) {
            return $this->groupBy(implode(',', $group));
        }
        return $this->groupBy($group);
    }

    /**
     * distinct - 去重（ThinkPHP 风格）
     *
     * @return static
     */
    public function distinct(): static
    {
        $this->isDistinct = true;
        return $this;
    }

    /**
     * fetchSql - 返回 SQL 不执行（ThinkPHP 风格）
     *
     * @return string
     */
    public function fetchSql(): string
    {
        return $this->toSql();
    }

    /**
     * where 条件
     */
    public function where(string|array $column, mixed $operator = null, mixed $value = null): static
    {
        if (is_array($column)) {
            foreach ($column as $key => $value) {
                $this->wheres[] = "{$key} = ?";
                $this->bindings[] = $value;
            }
        } else {
            if ($value === null) {
                $value = $operator;
                $operator = '=';
            }
            $this->wheres[] = "{$column} {$operator} ?";
            $this->bindings[] = $value;
        }
        return $this;
    }

    /**
     * 条件查询
     * 根据条件动态添加查询约束
     *
     * @example Db::table('users')->when($search, fn($query) => $query->where('name', 'like', "%{$search}%"))->get()
     */
    public function when(mixed $value, callable $callback, ?callable $default = null): static
    {
        if ($value) {
            $callback($this);
        } elseif ($default) {
            $default($this);
        }
        return $this;
    }

    /**
     * or where
     */
    public function orWhere(string $column, mixed $operator, mixed $value = null): static
    {
        if ($value === null) {
            $value = $operator;
            $operator = '=';
        }
        $this->wheres[] = "OR {$column} {$operator} ?";
        $this->bindings[] = $value;
        return $this;
    }

    /**
     * where in
     */
    public function whereIn(string $column, array $values): static
    {
        $placeholders = implode(', ', array_fill(0, count($values), '?'));
        $this->wheres[] = "{$column} IN ({$placeholders})";
        $this->bindings = array_merge($this->bindings, $values);
        return $this;
    }

    /**
     * where not in
     */
    public function whereNotIn(string $column, array $values): static
    {
        $placeholders = implode(', ', array_fill(0, count($values), '?'));
        $this->wheres[] = "{$column} NOT IN ({$placeholders})";
        $this->bindings = array_merge($this->bindings, $values);
        return $this;
    }

    /**
     * where null
     */
    public function whereNull(string $column): static
    {
        $this->wheres[] = "{$column} IS NULL";
        return $this;
    }

    /**
     * where not null
     */
    public function whereNotNull(string $column): static
    {
        $this->wheres[] = "{$column} IS NOT NULL";
        return $this;
    }

    /**
     * where between
     */
    public function whereBetween(string $column, mixed $min, mixed $max): static
    {
        $this->wheres[] = "{$column} BETWEEN ? AND ?";
        $this->bindings[] = $min;
        $this->bindings[] = $max;
        return $this;
    }

    /**
     * limit
     */
    public function limit(int $limit): static
    {
        $this->limit = $limit;
        return $this;
    }

    /**
     * offset
     */
    public function offset(int $offset): static
    {
        $this->offset = $offset;
        return $this;
    }

    /**
     * 排序
     */
    public function orderBy(string $column, string $direction = 'ASC'): static
    {
        $this->orderBy = $column;
        $this->orderDirection = strtoupper($direction) === 'DESC' ? 'DESC' : 'ASC';
        return $this;
    }

    /**
     * 多个排序
     */
    public function orderByRaw(string $sql): static
    {
        $this->orderBy = $sql;
        return $this;
    }

    /**
     * Join 连接
     */
    public function join(string $table, string $first, string $operator, string $second, string $type = 'INNER'): static
    {
        $this->joins[] = "{$type} JOIN {$table} ON {$first} {$operator} {$second}";
        return $this;
    }

    /**
     * Left Join
     */
    public function leftJoin(string $table, string $first, string $operator, string $second): static
    {
        return $this->join($table, $first, $operator, $second, 'LEFT');
    }

    /**
     * Right Join
     */
    public function rightJoin(string $table, string $first, string $operator, string $second): static
    {
        return $this->join($table, $first, $operator, $second, 'RIGHT');
    }

    /**
     * Union 查询
     */
    public function union(QueryBuilder $query, string $type = 'UNION'): static
    {
        $this->unionQueries[] = ['query' => $query, 'type' => $type];
        return $this;
    }

    /**
     * Union All
     */
    public function unionAll(QueryBuilder $query): static
    {
        return $this->union($query, 'UNION ALL');
    }

    /**
     * 分组
     */
    public function groupBy(string $column): static
    {
        $this->groupBy = $column;
        return $this;
    }

    /**
     * having
     */
    public function having(string $condition): static
    {
        $this->having = $condition;
        return $this;
    }

    /**
     * 通过主键查找
     */
    public function find(mixed $id): ?array
    {
        $this->where($this->primaryKey, '=', $id);
        $this->limit(1);
        return $this->fetchOne();
    }

    /**
     * 获取所有记录
     */
    public function get(): array
    {
        return $this->fetchAll();
    }

    /**
     * 获取第一条记录
     */
    public function first(): ?array
    {
        $this->limit(1);
        return $this->fetchOne();
    }

    /**
     * 获取单列值
     */
    public function value(string $column): mixed
    {
        $this->select([$column]);
        $result = $this->first();
        return $result[$column] ?? null;
    }

    /**
     * 获取单列列表
     */
    public function pluck(string $column): array
    {
        $results = $this->select([$column])->get();
        return array_column($results, $column);
    }

    /**
     * 检查记录是否存在
     */
    public function exists(): bool
    {
        $this->limit(1);
        $sql = $this->buildSelect();
        try {
            $result = $this->connection->select($sql, $this->bindings);
            return !empty($result);
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * 统计数量
     */
    public function count(string $column = '*'): int
    {
        $this->select(["COUNT({$column}) as aggregate"]);
        $result = $this->first();
        return (int) ($result['aggregate'] ?? 0);
    }

    /**
     * 求和
     */
    public function sum(string $column): float|int
    {
        $this->select(["SUM({$column}) as aggregate"]);
        $result = $this->first();
        return $result['aggregate'] ?? 0;
    }

    /**
     * 平均值
     */
    public function avg(string $column): float|int
    {
        $this->select(["AVG({$column}) as aggregate"]);
        $result = $this->first();
        return $result['aggregate'] ?? 0;
    }

    /**
     * 最大值
     */
    public function max(string $column): mixed
    {
        $this->select(["MAX({$column}) as aggregate"]);
        $result = $this->first();
        return $result['aggregate'] ?? null;
    }

    /**
     * 最小值
     */
    public function min(string $column): mixed
    {
        $this->select(["MIN({$column}) as aggregate"]);
        $result = $this->first();
        return $result['aggregate'] ?? null;
    }

    /**
     * 插入数据
     */
    public function insert(array $data): bool
    {
        $columns = array_keys($data);
        $values = array_values($data);
        $placeholders = implode(', ', array_fill(0, count($values), '?'));

        $sql = sprintf(
            'INSERT INTO %s (%s) VALUES (%s)',
            $this->table,
            implode(', ', $columns),
            $placeholders
        );

        try {
            return $this->connection->insert($sql, $values);
        } catch (\Throwable $e) {
            throw new QueryException(
                "插入失败: {$e->getMessage()}",
                previous: $e,
                sql: $sql,
                bindings: $values
            );
        }
    }

    /**
     * 批量插入
     */
    public function insertAll(array $records): bool
    {
        if (empty($records)) {
            return false;
        }

        $columns = array_keys($records[0]);
        $placeholders = implode(', ', array_fill(0, count($columns), '?'));
        $values = [];

        foreach ($records as $record) {
            foreach ($record as $value) {
                $values[] = $value;
            }
        }

        $sql = sprintf(
            'INSERT INTO %s (%s) VALUES %s',
            $this->table,
            implode(', ', $columns),
            implode(', ', array_fill(0, count($records), "({$placeholders})"))
        );

        try {
            return $this->connection->insert($sql, $values);
        } catch (\Throwable $e) {
            throw new QueryException(
                "批量插入失败: {$e->getMessage()}",
                previous: $e,
                sql: $sql,
                bindings: $values
            );
        }
    }

    /**
     * 更新数据
     */
    public function update(array $data): int
    {
        $sets = [];
        $values = [];

        foreach ($data as $key => $value) {
            $sets[] = "{$key} = ?";
            $values[] = $value;
        }

        $values = array_merge($values, $this->bindings);

        $sql = sprintf(
            'UPDATE %s SET %s',
            $this->table,
            implode(', ', $sets)
        );

        if (!empty($this->wheres)) {
            $sql .= ' WHERE ' . implode(' AND ', $this->wheres);
        }

        try {
            return $this->connection->update($sql, $values);
        } catch (\Throwable $e) {
            throw new QueryException(
                "更新失败: {$e->getMessage()}",
                previous: $e,
                sql: $sql,
                bindings: $values
            );
        }
    }

    /**
     * 自增
     */
    public function inc(string $column, int $amount = 1): int
    {
        $sql = sprintf(
            'UPDATE %s SET %s = %s + ?',
            $this->table,
            $column,
            $column
        );

        $bindings = [$amount];
        if (!empty($this->wheres)) {
            $sql .= ' WHERE ' . implode(' AND ', $this->wheres);
            $bindings = array_merge($bindings, $this->bindings);
        }

        try {
            return $this->connection->update($sql, $bindings);
        } catch (\Throwable $e) {
            throw new QueryException(
                "自增失败: {$e->getMessage()}",
                previous: $e,
                sql: $sql,
                bindings: $bindings
            );
        }
    }

    /**
     * 自减
     */
    public function dec(string $column, int $amount = 1): int
    {
        $sql = sprintf(
            'UPDATE %s SET %s = %s - ?',
            $this->table,
            $column,
            $column
        );

        $bindings = [$amount];
        if (!empty($this->wheres)) {
            $sql .= ' WHERE ' . implode(' AND ', $this->wheres);
            $bindings = array_merge($bindings, $this->bindings);
        }

        try {
            return $this->connection->update($sql, $bindings);
        } catch (\Throwable $e) {
            throw new QueryException(
                "自减失败: {$e->getMessage()}",
                previous: $e,
                sql: $sql,
                bindings: $bindings
            );
        }
    }

    /**
     * 删除
     */
    public function delete(): int
    {
        $sql = sprintf('DELETE FROM %s', $this->table);

        if (!empty($this->wheres)) {
            $sql .= ' WHERE ' . implode(' AND ', $this->wheres);
        }

        try {
            return $this->connection->delete($sql, $this->bindings);
        } catch (\Throwable $e) {
            throw new QueryException(
                "删除失败: {$e->getMessage()}",
                previous: $e,
                sql: $sql,
                bindings: $this->bindings
            );
        }
    }

    /**
     * 执行语句
     */
    public function statement(string $sql): bool
    {
        try {
            return $this->connection->statement($sql);
        } catch (\Throwable $e) {
            throw new QueryException(
                "语句执行失败: {$e->getMessage()}",
                previous: $e,
                sql: $sql
            );
        }
    }

    /**
     * 分页
     */
    public function paginate(int $page = 1, int $perPage = 15): array
    {
        $total = $this->count();
        $offset = ($page - 1) * $perPage;

        $items = $this->limit($perPage)->offset($offset)->get();

        return [
            'total' => $total,
            'per_page' => $perPage,
            'current_page' => $page,
            'last_page' => $total > 0 ? (int) ceil($total / $perPage) : 1,
            'items' => $items,
        ];
    }

    /**
     * 设置主键名
     */
    public function setPrimaryKey(string $key): static
    {
        $this->primaryKey = $key;
        return $this;
    }

    /**
     * 内部方法：执行查询获取所有
     */
    protected function fetchAll(): array
    {
        $sql = $this->buildSelect();

        try {
            return $this->connection->select($sql, $this->bindings);
        } catch (\Throwable $e) {
            throw new QueryException(
                "查询失败: {$e->getMessage()}",
                previous: $e,
                sql: $sql,
                bindings: $this->bindings
            );
        }
    }

    /**
     * 内部方法：执行查询获取一条
     */
    protected function fetchOne(): ?array
    {
        $results = $this->fetchAll();
        return $results[0] ?? null;
    }

    /**
     * 构建 SQL
     */
    protected function buildSelect(): string
    {
        $table = !empty($this->tableAlias) ? $this->tableAlias : $this->table;
        $columns = $this->isDistinct ? 'DISTINCT ' . implode(', ', $this->columns) : implode(', ', $this->columns);

        $sql = sprintf(
            'SELECT %s FROM %s',
            $columns,
            $table
        );

        if (!empty($this->joins)) {
            $sql .= ' ' . implode(' ', $this->joins);
        }

        if (!empty($this->wheres)) {
            $sql .= ' WHERE ' . implode(' AND ', $this->wheres);
        }

        if ($this->groupBy) {
            $sql .= " GROUP BY {$this->groupBy}";
        }

        if ($this->having) {
            $sql .= " HAVING {$this->having}";
        }

        if ($this->orderBy) {
            $sql .= " ORDER BY {$this->orderBy}";
            if ($this->orderDirection !== 'ASC' && $this->orderDirection !== 'DESC') {
                $sql .= " {$this->orderDirection}";
            }
        }

        if ($this->limit !== null) {
            $sql .= " LIMIT {$this->limit}";
        }

        if ($this->offset !== null) {
            $sql .= " OFFSET {$this->offset}";
        }

        if ($this->lockFor !== null) {
            $sql .= " {$this->lockFor}";
        }

        foreach ($this->unionQueries as $union) {
            $sql .= " {$union['type']} {$union['query']->toSql()}";
        }

        return $sql;
    }

    /**
     * 获取绑定参数
     */
    public function getBindings(): array
    {
        return $this->bindings;
    }

    /**
     * 获取 SQL
     */
    public function toSql(): string
    {
        return $this->buildSelect();
    }

    /**
     * 清空条件
     */
    public function clear(): static
    {
        $this->columns = ['*'];
        $this->wheres = [];
        $this->bindings = [];
        $this->limit = null;
        $this->offset = null;
        $this->orderBy = '';
        $this->orderDirection = 'ASC';
        $this->groupBy = null;
        $this->having = null;
        $this->lockFor = null;
        $this->tableAlias = '';
        $this->isDistinct = false;
        return $this;
    }

    /**
     * 批量查询 - 分块处理大量数据
     *
     * @param callable $callback 回调函数，接收一维数组记录
     * @param int $chunkSize 每块数量
     * @return bool
     * @example Db::table('users')->chunk(function ($users) { foreach ($users as $user) { ... } }, 1000)
     */
    public function chunk(callable $callback, int $chunkSize = 1000): bool
    {
        $page = 1;

        do {
            $results = $this->forPage($page, $chunkSize)->get();
            $count = count($results);

            if ($count === 0) {
                break;
            }

            if ($callback($results) === false) {
                return false;
            }

            $page++;

            if ($count < $chunkSize) {
                break;
            }
        } while (true);

        return true;
    }

    /**
     * 分块查询直到条件不满足
     *
     * @param callable $callback 回调函数，返回 false 停止
     * @param int $chunkSize 每块数量
     * @return bool
     */
    public function chunkUntilStop(callable $callback, int $chunkSize = 1000): bool
    {
        return $this->chunk($callback, $chunkSize);
    }

    /**
     * 分页查询
     *
     * @param int $page 页码
     * @param int $perPage 每页数量
     * @return static
     */
    public function forPage(int $page, int $perPage = 15): static
    {
        $this->limit($perPage);
        $this->offset(($page - 1) * $perPage);
        return $this;
    }

    /**
     * 游标查询 - 适合大结果集遍历
     *
     * @param callable $callback 回调函数
     * @param int $fetchSize 每次获取数量
     * @return bool
     * @example Db::table('users')->cursor(function ($user) { process($user); })
     */
    public function cursor(callable $callback, int $fetchSize = 1000): bool
    {
        return $this->chunk($callback, $fetchSize);
    }

    /**
     * 批量插入（支持 chunk）
     *
     * @param array $records 记录数组
     * @param int $chunkSize 分块大小
     * @return int 成功插入的行数
     */
    public function insertChunk(array $records, int $chunkSize = 1000): int
    {
        $totalInserted = 0;
        $chunks = array_chunk($records, $chunkSize);

        foreach ($chunks as $chunk) {
            if ($this->insertAll($chunk)) {
                $totalInserted += count($chunk);
            }
        }

        return $totalInserted;
    }

    /**
     * Upsert - 插入或更新（基于唯一键）
     *
     * @param array $data 数据
     * @param array $uniqueKeys 唯一键列表
     * @param array $updateKeys 更新时更新的字段（默认全部非唯一键字段）
     * @return bool
     * @example Db::table('users')->upsert(['email' => 'test@example.com', 'name' => 'test'], ['email'], ['name'])
     */
    public function upsert(array $data, array $uniqueKeys, array $updateKeys = []): bool
    {
        if (empty($data) || empty($uniqueKeys)) {
            return false;
        }

        $where = [];
        foreach ($uniqueKeys as $key) {
            if (isset($data[$key])) {
                $where[$key] = $data[$key];
            }
        }

        $exists = (bool) $this->where($where)->exists();

        if ($exists) {
            $updateData = [];
            if (empty($updateKeys)) {
                foreach ($data as $key => $value) {
                    if (!in_array($key, $uniqueKeys, true)) {
                        $updateData[$key] = $value;
                    }
                }
            } else {
                foreach ($updateKeys as $key) {
                    if (isset($data[$key])) {
                        $updateData[$key] = $data[$key];
                    }
                }
            }

            if (!empty($updateData)) {
                return $this->where($where)->update($updateData) > 0;
            }
            return false;
        }

        return $this->insert($data);
    }

    /**
     * 批量 Upsert
     *
     * @param array $records 记录数组
     * @param array $uniqueKeys 唯一键列表
     * @param array $updateKeys 更新时更新的字段
     * @return int 成功操作数
     */
    public function upsertAll(array $records, array $uniqueKeys, array $updateKeys = []): int
    {
        $affected = 0;
        foreach ($records as $record) {
            if ($this->upsert($record, $uniqueKeys, $updateKeys)) {
                $affected++;
            }
        }
        return $affected;
    }

    /**
     * 检查是否不存在
     */
    public function doesntExist(): bool
    {
        return !$this->exists();
    }

    /**
     * 添加 where not
     */
    public function whereNot(string $column, mixed $operator, mixed $value = null): static
    {
        if ($value === null) {
            $value = $operator;
            $operator = '!=';
        }
        $this->wheres[] = "NOT {$column} {$operator} ?";
        $this->bindings[] = $value;
        return $this;
    }

    /**
     * or where not
     */
    public function orWhereNot(string $column, mixed $operator, mixed $value = null): static
    {
        if ($value === null) {
            $value = $operator;
            $operator = '!=';
        }
        $this->wheres[] = "OR NOT {$column} {$operator} ?";
        $this->bindings[] = $value;
        return $this;
    }

    /**
     * first or create
     */
    public function firstOrCreate(array $attributes, array $values = []): ?array
    {
        $exists = $this->where($attributes)->exists();

        if ($exists) {
            return $this->where($attributes)->first();
        }

        $data = array_merge($attributes, $values);
        if ($this->insert($data)) {
            return $this->where($attributes)->first();
        }

        return null;
    }

    /**
     * update or create
     */
    public function updateOrCreate(array $search, array $values = []): bool
    {
        $exists = $this->where($search)->exists();

        if ($exists) {
            return $this->where($search)->update($values) > 0;
        }

        $data = array_merge($search, $values);
        return $this->insert($data);
    }

    /**
     * 聚合函数 - 多个聚合
     *
     * @param array $aggregates 聚合配置 ['count' => 'id', 'sum' => 'balance', 'avg' => 'score']
     * @return array
     * @example Db::table('users')->aggregates(['count' => '*', 'sum' => 'balance', 'avg' => 'score'])
     */
    public function aggregates(array $aggregates): array
    {
        $result = [];

        foreach ($aggregates as $func => $column) {
            $result[$func . '_' . $column] = match (strtolower($func)) {
                'count' => $this->count($column),
                'sum' => $this->sum($column),
                'avg' => $this->avg($column),
                'max' => $this->max($column),
                'min' => $this->min($column),
                default => 0,
            };
        }

        return $result;
    }

    /**
     * 锁定行
     *
     * @param string $type 锁定类型 (FOR UPDATE, LOCK IN SHARE MODE)
     * @return static
     */
    public function lock(string $type = 'FOR UPDATE'): static
    {
        $this->lockFor = $type;
        return $this;
    }

    /**
     * 共享锁
     */
    public function sharedLock(): static
    {
        return $this->lock('LOCK IN SHARE MODE');
    }
}
