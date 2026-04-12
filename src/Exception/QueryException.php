<?php

declare(strict_types=1);

namespace Kode\Database\Exception;

/**
 * 查询异常
 */
class QueryException extends DatabaseException
{
    protected string $sql = '';
    protected array $bindings = [];

    public function __construct(
        string $sql = '',
        array $bindings = [],
        ?string $message = null,
        int $code = 0,
        ?\Throwable $previous = null
    ) {
        $this->sql = $sql;
        $this->bindings = $bindings;
        $message = $message ?? "SQL Error: {$sql}";
        parent::__construct($message, $code, $previous, $sql, $bindings);
    }

    public static function make(string $sql, array $bindings = [], ?string $message = null): static
    {
        return new static($sql, $bindings, $message, 0, null);
    }

    public static function sqlError(string $sql, array $bindings = []): static
    {
        return new static($sql, $bindings, "SQL statement failed: {$sql}", 0, null);
    }

    public static function tableNotFound(string $table): static
    {
        return new static('', [], "Table [{$table}] not found", 0, null);
    }

    public static function columnNotFound(string $column, string $table = ''): static
    {
        $tableInfo = $table ? " in table [{$table}]" : '';
        return new static('', [], "Column [{$column}]{$tableInfo} not found", 0, null);
    }

    public static function insertFailed(string $sql, array $bindings, \Throwable $previous): static
    {
        return new static($sql, $bindings, "插入失败: {$previous->getMessage()}", 0, $previous);
    }

    public static function updateFailed(string $sql, array $bindings, \Throwable $previous): static
    {
        return new static($sql, $bindings, "更新失败: {$previous->getMessage()}", 0, $previous);
    }

    public static function deleteFailed(string $sql, array $bindings, \Throwable $previous): static
    {
        return new static($sql, $bindings, "删除失败: {$previous->getMessage()}", 0, $previous);
    }

    public static function queryFailed(string $sql, array $bindings, \Throwable $previous): static
    {
        return new static($sql, $bindings, "查询失败: {$previous->getMessage()}", 0, $previous);
    }
}
