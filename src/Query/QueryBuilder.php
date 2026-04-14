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
     * where not between
     */
    public function whereNotBetween(string $column, mixed $min, mixed $max): static
    {
        $this->wheres[] = "{$column} NOT BETWEEN ? AND ?";
        $this->bindings[] = $min;
        $this->bindings[] = $max;
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
     * or where null
     */
    public function orWhereNull(string $column): static
    {
        $this->wheres[] = "OR {$column} IS NULL";
        return $this;
    }

    /**
     * or where not null
     */
    public function orWhereNotNull(string $column): static
    {
        $this->wheres[] = "OR {$column} IS NOT NULL";
        return $this;
    }

    /**
     * where date
     */
    public function whereDate(string $column, string $operator, string $value): static
    {
        $this->wheres[] = "DATE({$column}) {$operator} ?";
        $this->bindings[] = $value;
        return $this;
    }

    /**
     * where month
     */
    public function whereMonth(string $column, string $operator, string $value): static
    {
        $this->wheres[] = "MONTH({$column}) {$operator} ?";
        $this->bindings[] = $value;
        return $this;
    }

    /**
     * where day
     */
    public function whereDay(string $column, string $operator, string $value): static
    {
        $this->wheres[] = "DAY({$column}) {$operator} ?";
        $this->bindings[] = $value;
        return $this;
    }

    /**
     * where year
     */
    public function whereYear(string $column, string $operator, string $value): static
    {
        $this->wheres[] = "YEAR({$column}) {$operator} ?";
        $this->bindings[] = $value;
        return $this;
    }

    /**
     * where time
     */
    public function whereTime(string $column, string $operator, string $value): static
    {
        $this->wheres[] = "TIME({$column}) {$operator} ?";
        $this->bindings[] = $value;
        return $this;
    }

    /**
     * where column (比较两列)
     */
    public function whereColumn(string $column1, string $operator, string $column2): static
    {
        $this->wheres[] = "{$column1} {$operator} {$column2}";
        return $this;
    }

    /**
     * where raw
     */
    public function whereRaw(string $sql, array $bindings = []): static
    {
        $this->wheres[] = $sql;
        $this->bindings = array_merge($this->bindings, $bindings);
        return $this;
    }

    /**
     * or where raw
     */
    public function orWhereRaw(string $sql, array $bindings = []): static
    {
        $this->wheres[] = "OR " . $sql;
        $this->bindings = array_merge($this->bindings, $bindings);
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
     *
     * @param QueryBuilder|string $query 联合的查询
     * @param string $type UNION 类型
     * @return static
     */
    public function union(QueryBuilder|string $query, string $type = 'UNION'): static
    {
        if (is_string($query)) {
            $unionQuery = new self($this->connection);
            $unionQuery->table($query);
        } else {
            $unionQuery = $query;
        }

        $this->unionQueries[] = ['query' => $unionQuery, 'type' => $type];
        return $this;
    }

    /**
     * Union All
     *
     * @param QueryBuilder|string $query 联合的查询
     */
    public function unionAll(QueryBuilder|string $query): static
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
            throw QueryException::insertFailed($sql, $values, $e);
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
            throw QueryException::insertFailed($sql, $values, $e);
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
            throw QueryException::updateFailed($sql, $values, $e);
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
            throw QueryException::updateFailed($sql, $bindings, $e);
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
            throw QueryException::updateFailed($sql, $bindings, $e);
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
            throw QueryException::deleteFailed($sql, $this->bindings, $e);
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
            throw QueryException::queryFailed($sql, [], $e);
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
            throw QueryException::queryFailed($sql, $this->bindings, $e);
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
     * 清空 WHERE 条件
     */
    public function clearWhere(): static
    {
        $this->wheres = [];
        $this->bindings = array_filter($this->bindings, fn($key) => !str_starts_with($key, 'where_'), ARRAY_FILTER_USE_KEY);
        return $this;
    }

    /**
     * 清空排序
     */
    public function clearOrderBy(): static
    {
        $this->orderBy = '';
        $this->orderDirection = 'ASC';
        return $this;
    }

    /**
     * 清空 limit 和 offset
     */
    public function clearLimit(): static
    {
        $this->limit = null;
        $this->offset = null;
        return $this;
    }

    /**
     * 清空所有
     */
    public function reset(): static
    {
        return $this->clear();
    }

    /**
     * 获取查询信息摘要
     *
     * @return array
     */
    public function toInfo(): array
    {
        return [
            'table' => $this->table,
            'columns' => $this->columns,
            'wheres' => $this->wheres,
            'limit' => $this->limit,
            'offset' => $this->offset,
            'orderBy' => $this->orderBy ? "{$this->orderBy} {$this->orderDirection}" : null,
            'bindings' => $this->bindings,
        ];
    }

    /**
     * 检查查询条件是否为空
     *
     * @return bool
     */
    public function hasConditions(): bool
    {
        return !empty($this->wheres);
    }

    /**
     * 获取条件数量
     *
     * @return int
     */
    public function whereCount(): int
    {
        return count($this->wheres);
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
     * 插入或忽略（唯一键冲突时忽略）
     *
     * @param array $data 数据
     * @return bool
     * @example Db::table('users')->insertOrIgnore(['email' => 'test@example.com', 'name' => 'test'])
     */
    public function insertOrIgnore(array $data): bool
    {
        if (empty($data)) {
            return false;
        }

        $columns = array_keys($data);
        $values = array_values($data);
        $placeholders = implode(', ', array_fill(0, count($values), '?'));

        $sql = sprintf(
            'INSERT IGNORE INTO %s (%s) VALUES (%s)',
            $this->table,
            implode(', ', $columns),
            $placeholders
        );

        try {
            return $this->connection->insert($sql, $values);
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * 条件更新 - 满足条件时才更新
     *
     * @param array $data 更新数据
     * @param callable $condition 条件闭包
     * @return int
     * @example Db::table('users')->updateIf($data, fn($q) => $q->where('status', 1))
     */
    public function updateIf(array $data, callable $condition): int
    {
        if (empty($data)) {
            return 0;
        }

        $condition($this);
        return $this->update($data);
    }

    /**
     * 条件删除 - 满足条件时才删除
     *
     * @param callable $condition 条件闭包
     * @return int
     * @example Db::table('users')->deleteIf(fn($q) => $q->where('status', 0))
     */
    public function deleteIf(callable $condition): int
    {
        $condition($this);
        return $this->delete();
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

    /**
     * 子查询
     *
     * @param callable $callback 回调函数
     * @return static
     * @example Db::table('users')->where('id', 'in', function($q) { $q->select('user_id')->from('orders'); })->get()
     */
    public function whereInSub(string $column, callable $callback): static
    {
        $subQuery = new self($this->connection);
        $callback($subQuery);

        $this->wheres[] = "{$column} IN ({$subQuery->toSql()})";
        $this->bindings = array_merge($this->bindings, $subQuery->getBindings());

        return $this;
    }

    /**
     * NOT IN 子查询
     *
     * @param string $column 列名
     * @param callable $callback 回调函数
     * @return static
     */
    public function whereNotInSub(string $column, callable $callback): static
    {
        $subQuery = new self($this->connection);
        $callback($subQuery);

        $this->wheres[] = "{$column} NOT IN ({$subQuery->toSql()})";
        $this->bindings = array_merge($this->bindings, $subQuery->getBindings());

        return $this;
    }

    /**
     * EXISTS 子查询
     *
     * @param callable $callback 回调函数
     * @return static
     */
    public function whereExists(callable $callback): static
    {
        $subQuery = new self($this->connection);
        $callback($subQuery);

        $this->wheres[] = "EXISTS ({$subQuery->toSql()})";
        $this->bindings = array_merge($this->bindings, $subQuery->getBindings());

        return $this;
    }

    /**
     * NOT EXISTS 子查询
     *
     * @param callable $callback 回调函数
     * @return static
     */
    public function whereNotExists(callable $callback): static
    {
        $subQuery = new self($this->connection);
        $callback($subQuery);

        $this->wheres[] = "NOT EXISTS ({$subQuery->toSql()})";
        $this->bindings = array_merge($this->bindings, $subQuery->getBindings());

        return $this;
    }

    /**
     * 获取查询构建器实例
     *
     * @return static
     */
    public function query(): static
    {
        return $this;
    }

    /**
     * 重新实例化
     *
     * @return static
     */
    public function newQuery(): static
    {
        $newBuilder = new self($this->connection);
        return $newBuilder;
    }

    /**
     * 复制当前查询构建器
     *
     * @return static
     */
    public function copy(): static
    {
        $copied = new self($this->connection);
        $copied->table = $this->table;
        $copied->columns = $this->columns;
        $copied->wheres = $this->wheres;
        $copied->bindings = $this->bindings;
        $copied->limit = $this->limit;
        $copied->offset = $this->offset;
        $copied->orderBy = $this->orderBy;
        $copied->orderDirection = $this->orderDirection;
        $copied->groupBy = $this->groupBy;
        $copied->having = $this->having;
        $copied->joins = $this->joins;
        $copied->unionQueries = $this->unionQueries;
        $copied->lockFor = $this->lockFor;
        $copied->tableAlias = $this->tableAlias;
        $copied->isDistinct = $this->isDistinct;
        return $copied;
    }

    /**
     * 切换表
     *
     * @param string $table 表名
     * @return static
     */
    public function fromTable(string $table): static
    {
        $this->table = $table;
        return $this;
    }

    /**
     * 强制使用索引
     *
     * @param string $index 索引名
     * @return static
     */
    public function useIndex(string $index): static
    {
        return $this;
    }

    /**
     * 忽略索引
     *
     * @param string $index 索引名
     * @return static
     */
    public function ignoreIndex(string $index): static
    {
        return $this;
    }

    /**
     * 限制结果数量（take 别名）
     *
     * @param int $limit 限制数量
     * @return static
     * @example Db::table('users')->limitBy(10)->get()
     */
    public function limitBy(int $limit): static
    {
        return $this->limit($limit);
    }

    /**
     * 取前 N 条记录
     *
     * @param int $limit 数量
     * @return static
     * @example Db::table('users')->take(10)->get()
     */
    public function take(int $limit): static
    {
        return $this->limit($limit);
    }

    /**
     * 跳过 N 条记录
     *
     * @param int $offset 跳过数量
     * @return static
     * @example Db::table('users')->skip(10)->take(10)->get()
     */
    public function skip(int $offset): static
    {
        return $this->offset($offset);
    }

    /**
     * 获取键值对
     *
     * @param string $key 键字段
     * @param string|null $value 值字段
     * @return array
     * @example Db::table('users')->lists('id', 'name')
     */
    public function lists(string $key, ?string $value = null): array
    {
        if ($value === null) {
            return $this->pluck($key);
        }

        $results = $this->select([$key, $value])->get();
        $result = [];
        foreach ($results as $row) {
            $result[$row[$key]] = $row[$value];
        }
        return $result;
    }

    /**
     * 打印 SQL（调试用）
     *
     * @return static
     * @example Db::table('users')->where('id', 1)->dump()
     */
    public function dump(): static
    {
        echo "SQL: " . $this->toSql() . PHP_EOL;
        echo "Bindings: " . json_encode($this->bindings) . PHP_EOL;
        return $this;
    }

    /**
     * 打印 SQL 并终止（调试用）
     *
     * @return never
     * @example Db::table('users')->where('id', 1)->dd()
     */
    public function dd(): never
    {
        $this->dump();
        exit(1);
    }

    /**
     * 检查是否为空
     *
     * @return bool
     * @example Db::table('users')->isEmpty()
     */
    public function isEmpty(): bool
    {
        return $this->count() === 0;
    }

    /**
     * 检查是否不为空
     *
     * @return bool
     * @example Db::table('users')->isNotEmpty()
     */
    public function isNotEmpty(): bool
    {
        return !$this->isEmpty();
    }

    /**
     * 批量更新（基于指定字段）
     *
     * @param array $data 数据数组
     * @param string $keyField 主键字段名
     * @return int 影响行数
     * @example Db::table('users')->batchUpdateBy([['id' => 1, 'name' => 'a'], ['id' => 2, 'name' => 'b']], 'id')
     */
    public function batchUpdateBy(array $data, string $keyField = 'id'): int
    {
        if (empty($data)) {
            return 0;
        }

        $affected = 0;
        foreach ($data as $record) {
            if (!isset($record[$keyField])) {
                continue;
            }

            $keyValue = $record[$keyField];
            unset($record[$keyField]);

            if (!empty($record)) {
                $affected += $this->where($keyField, '=', $keyValue)->update($record);
            }
        }

        return $affected;
    }

    /**
     * 批量删除（基于指定字段）
     *
     * @param array $values 值数组
     * @param string $field 字段名
     * @return int 影响行数
     * @example Db::table('users')->batchDeleteBy([1, 2, 3], 'id')
     */
    public function batchDeleteBy(array $values, string $field = 'id'): int
    {
        if (empty($values)) {
            return 0;
        }

        return $this->whereIn($field, $values)->delete();
    }

    /**
     * 查找或创建
     *
     * @param array $attributes 创建条件
     * @param array $values 创建数据
     * @return array|null
     * @example Db::table('users')->findOrCreate(['email' => 'test@example.com'], ['name' => 'test'])
     */
    public function findOrCreate(array $attributes, array $values = []): ?array
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
     * 查找或失败（抛出异常）
     *
     * @param array $attributes 查找条件
     * @return array
     * @throws \Kode\Database\Exception\ModelNotFoundException
     */
    public function findOrFail(array $attributes): array
    {
        $result = $this->where($attributes)->first();

        if ($result === null) {
            throw \Kode\Database\Exception\ModelNotFoundException::notFound('Record');
        }

        return $result;
    }

    /**
     * 首条记录或失败（抛出异常）
     *
     * @return array
     * @throws \Kode\Database\Exception\ModelNotFoundException
     */
    public function firstOrFail(): array
    {
        $result = $this->first();

        if ($result === null) {
            throw \Kode\Database\Exception\ModelNotFoundException::notFound('Record');
        }

        return $result;
    }

    /**
     * 分块处理结果集（按主键）
     *
     * @param int $chunkSize 块大小
     * @param callable $callback 回调函数
     * @param string $column 主键列名
     * @return bool
     */
    public function chunkById(int $chunkSize, callable $callback, string $column = 'id'): bool
    {
        $lastId = 0;

        do {
            $results = $this->where($column, '>', $lastId)
                ->orderBy($column)
                ->limit($chunkSize)
                ->get();

            if (empty($results)) {
                break;
            }

            foreach ($results as $result) {
                $lastId = $result[$column];
            }

            if (!$callback($results, $lastId)) {
                return false;
            }
        } while (count($results) === $chunkSize);

        return true;
    }

    /**
     * 是否存在任意一条记录
     *
     * @param callable|null $callback 条件回调
     * @return bool
     */
    public function existsBy(?callable $callback = null): bool
    {
        if ($callback !== null) {
            $callback($this);
        }
        return $this->exists();
    }

    /**
     * 获取第一条记录
     *
     * @return array|null
     */
    public function begin(): ?array
    {
        return $this->first();
    }

    /**
     * 获取最后一条记录
     *
     * @return array|null
     */
    public function end(): ?array
    {
        return $this->orderBy('id', 'desc')->first();
    }

    /**
     * 获取第 N 条记录
     *
     * @param int $offset 偏移量
     * @return array|null
     */
    public function nth(int $offset): ?array
    {
        return $this->offset($offset)->limit(1)->first();
    }

    /**
     * 获取随机记录
     *
     * @param int $count 数量
     * @return array
     */
    public function random(int $count = 1): array
    {
        if ($count === 1) {
            return $this->orderByRaw('RAND()')->first() ?? [];
        }

        return $this->orderByRaw('RAND()')->limit($count)->get();
    }

    /**
     * 检查记录是否不存在
     *
     * @return bool
     */
    public function notExists(): bool
    {
        return !$this->exists();
    }

    /**
     * 统计字段值出现次数
     *
     * @param string $field 字段名
     * @param string|null $index 分组索引字段
     * @return array
     */
    public function countBy(string $field, ?string $index = null): array
    {
        $sql = "SELECT {$field}, COUNT(*) as count FROM {$this->table}";

        if (!empty($this->wheres)) {
            $sql .= ' WHERE ' . implode(' AND ', $this->wheres);
        }

        $sql .= " GROUP BY {$field}";

        $results = $this->connection->select($sql, $this->bindings);

        if ($index === null) {
            $result = [];
            foreach ($results as $row) {
                $key = $row[$field] ?? array_values($row)[0];
                $result[$key] = $row['count'];
            }
            return $result;
        }

        $result = [];
        foreach ($results as $row) {
            $result[$row[$index]] = $row;
        }
        return $result;
    }

    /**
     * 检验查询条件
     *
     * @param callable $callback 回调函数
     * @return $this
     */
    public function tap(callable $callback): static
    {
        $callback($this);
        return $this;
    }

    /**
     * 添加全局作用域
     *
     * @param callable $scope 作用域回调
     * @return $this
     */
    public function withScope(callable $scope): static
    {
        $scope($this);
        return $this;
    }

    /**
     * 生成原始 SQL 表达式
     *
     * @param string $expression 表达式
     * @param array $bindings 绑定参数
     * @return array
     */
    public function raw(string $expression, array $bindings = []): array
    {
        return ['raw' => true, 'expression' => $expression, 'bindings' => $bindings];
    }

    /**
     * 克隆方法
     */
    public function __clone()
    {
        foreach ($this->unionQueries as $key => $union) {
            $this->unionQueries[$key]['query'] = clone $union['query'];
        }
    }

    /**
     * 执行 explain 分析查询
     *
     * @return array
     */
    public function explain(): array
    {
        $sql = $this->buildSelect();
        return $this->connection->select("EXPLAIN {$sql}", $this->bindings);
    }

    /**
     * 获取查询摘要信息
     *
     * @return array
     */
    public function explainInfo(): array
    {
        $explain = $this->explain();
        return [
            'type' => $explain[0]['type'] ?? null,
            'possible_keys' => $explain[0]['possible_keys'] ?? null,
            'key' => $explain[0]['key'] ?? null,
            'key_len' => $explain[0]['key_len'] ?? null,
            'rows' => $explain[0]['rows'] ?? null,
            'extra' => $explain[0]['Extra'] ?? null,
        ];
    }

    /**
     * 生成查找 SQL（用于调试）
     *
     * @return string
     */
    public function toLookSql(): string
    {
        $sql = $this->buildSelect();
        $bindings = $this->bindings;

        foreach ($bindings as $key => $value) {
            if (is_int($key)) {
                $sql = preg_replace('/\?/', "'" . addslashes((string) $value) . "'", $sql, 1);
            } else {
                $sql = str_replace($key, "'" . addslashes((string) $value) . "'", $sql);
            }
        }

        return $sql;
    }

    /**
     * 获取记录数（带条件计数）
     *
     * @param string|null $column 计数字段
     * @return int
     */
    public function countWithConditions(?string $column = null): int
    {
        $originalColumns = $this->columns;
        $originalLimit = $this->limit;
        $originalOffset = $this->offset;

        $this->columns = ['*'];
        $this->limit = null;
        $this->offset = null;

        $field = $column ?? '*';
        $sql = "SELECT COUNT({$field}) as aggregate FROM {$this->table}";

        if (!empty($this->wheres)) {
            $sql .= ' WHERE ' . implode(' AND ', $this->wheres);
        }

        if ($this->groupBy) {
            $sql .= " GROUP BY {$this->groupBy}";
        }

        $result = $this->connection->select($sql, $this->bindings);
        $count = (int) ($result[0]['aggregate'] ?? 0);

        $this->columns = $originalColumns;
        $this->limit = $originalLimit;
        $this->offset = $originalOffset;

        return $count;
    }

    /**
     * 检查limit是否设置
     *
     * @return bool
     */
    public function hasLimit(): bool
    {
        return $this->limit !== null;
    }

    /**
     * 检查offset是否设置
     *
     * @return bool
     */
    public function hasOffset(): bool
    {
        return $this->offset !== null;
    }

    /**
     * 获取limit值
     *
     * @return int|null
     */
    public function getLimit(): ?int
    {
        return $this->limit;
    }

    /**
     * 获取offset值
     *
     * @return int|null
     */
    public function getOffset(): ?int
    {
        return $this->offset;
    }

    /**
     * 获取排序列
     *
     * @return string
     */
    public function getOrderBy(): string
    {
        return $this->orderBy;
    }

    /**
     * 获取排序方向
     *
     * @return string
     */
    public function getOrderDirection(): string
    {
        return $this->orderDirection;
    }

    /**
     * 验证SQL安全性（基本检查）
     *
     * @return bool
     */
    public function isSafe(): bool
    {
        $sql = $this->buildSelect();

        $dangerous = ['DROP ', 'DELETE ', 'TRUNCATE ', 'ALTER ', 'CREATE ', 'INSERT ', 'UPDATE ', 'REPLACE '];
        foreach ($dangerous as $keyword) {
            if (stripos($sql, $keyword) !== false) {
                return false;
            }
        }

        return true;
    }

    /**
     * 批量更新
     *
     * @param array $values 要更新的字段和值
     * @param array $whereConditions WHERE 条件
     * @return int 影响行数
     */
    public function updateBatch(array $values, array $whereConditions = []): int
    {
        if (empty($values)) {
            return 0;
        }

        $sets = [];
        $bindings = [];

        foreach ($values as $column => $value) {
            $sets[] = "{$column} = ?";
            $bindings[] = $value;
        }

        $sql = "UPDATE {$this->table} SET " . implode(', ', $sets);

        if (!empty($this->wheres)) {
            $sql .= ' WHERE ' . implode(' AND ', $this->wheres);
        }

        return $this->connection->statement($sql, $bindings);
    }

    /**
     * UPSERT 批量（插入或更新多行）
     *
     * @param array $values 二维数组
     * @param array $uniqueBy 唯一键
     * @param array|null $updateFields 更新时更新的字段
     * @return int 影响行数
     */
    public function upsertBatch(array $values, array $uniqueBy, ?array $updateFields = null): int
    {
        if (empty($values) || empty($uniqueBy)) {
            return 0;
        }

        $firstRow = $values[0];
        $columns = array_keys($firstRow);
        $columnCount = count($columns);
        $placeholders = '(' . implode(', ', array_fill(0, $columnCount, '?')) . ')';
        $allPlaceholders = implode(', ', array_fill(0, count($values), $placeholders));

        $sql = "INSERT INTO {$this->table} (" . implode(', ', $columns) . ") VALUES {$allPlaceholders}";

        $bindings = [];
        foreach ($values as $row) {
            foreach ($columns as $column) {
                $bindings[] = $row[$column] ?? null;
            }
        }

        if ($updateFields === null) {
            $updateFields = array_filter($columns, fn($col) => !in_array($col, $uniqueBy));
        }

        $sets = [];
        foreach ($updateFields as $field) {
            if (!in_array($field, $uniqueBy)) {
                $sets[] = "{$field} = VALUES({$field})";
            }
        }

        if (!empty($sets)) {
            $sql .= " ON DUPLICATE KEY UPDATE " . implode(', ', $sets);
        }

        return $this->connection->statement($sql, $bindings);
    }

    /**
     * 批量删除
     *
     * @param array $ids ID 数组
     * @param string $column 字段名，默认 id
     * @return int 影响行数
     */
    public function deleteBatch(array $ids, string $column = 'id'): int
    {
        if (empty($ids)) {
            return 0;
        }

        $placeholders = implode(', ', array_fill(0, count($ids), '?'));
        $sql = "DELETE FROM {$this->table} WHERE {$column} IN ({$placeholders})";

        return $this->connection->statement($sql, $ids);
    }

    /**
     * 分块处理并返回键值对
     *
     * @param string $key 键字段
     * @param string $value 值字段
     * @param int $chunkSize 每块大小
     * @return array
     */
    public function pluckWithKeys(string $key, string $value, int $chunkSize = 1000): array
    {
        $result = [];

        $this->chunk(function ($records) use ($key, $value, &$result) {
            foreach ($records as $record) {
                $result[$record[$key]] = $record[$value];
            }
        }, $chunkSize);

        return $result;
    }
}
