<?php

declare(strict_types=1);

namespace Kode\Database\Query;

use Kode\Database\Exception\QueryException;

/**
 * 查询构建器
 * 支持链式调用
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

    public function __construct(protected mixed $connection)
    {
    }

    /**
     * 设置表
     */
    public function from(string $table): static
    {
        $this->table = $table;
        return $this;
    }

    /**
     * 表别名
     */
    public function table(string $table): static
    {
        return $this->from($table);
    }

    /**
     * 设置列
     */
    public function select(array|string ...$columns): static
    {
        $this->columns = is_array($columns[0] ?? null) ? $columns[0] : $columns;
        return $this;
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
     * 执行查询
     */
    public function get(): array
    {
        $sql = $this->buildSelect();

        try {
            return $this->connection->select($sql, $this->bindings);
        } catch (\Throwable $e) {
            throw new QueryException(
                "查询执行失败: {$e->getMessage()}",
                previous: $e,
                sql: $sql,
                bindings: $this->bindings
            );
        }
    }

    /**
     * 获取第一条
     */
    public function first(): ?array
    {
        $this->limit(1);
        $results = $this->get();
        return $results[0] ?? null;
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
     * 插入
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
     * 更新
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
    public function increment(string $column, int $amount = 1): int
    {
        $data = [$column => $column . ' + ' . $amount];
        return $this->update($data);
    }

    /**
     * 自减
     */
    public function decrement(string $column, int $amount = 1): int
    {
        $data = [$column => $column . ' - ' . $amount];
        return $this->update($data);
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
            'last_page' => ceil($total / $perPage),
            'items' => $items,
        ];
    }

    /**
     * 构建 SQL
     */
    protected function buildSelect(): string
    {
        $sql = sprintf(
            'SELECT %s FROM %s',
            implode(', ', $this->columns),
            $this->table
        );

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
            $sql .= " ORDER BY {$this->orderBy} {$this->orderDirection}";
        }

        if ($this->limit !== null) {
            $sql .= " LIMIT {$this->limit}";
        }

        if ($this->offset !== null) {
            $sql .= " OFFSET {$this->offset}";
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
        return $this;
    }
}
