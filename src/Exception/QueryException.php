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
        return new static($sql, $bindings, $message);
    }

    public static function sqlError(string $sql, array $bindings = []): static
    {
        return new static($sql, $bindings, "SQL statement failed: {$sql}");
    }

    public static function tableNotFound(string $table): static
    {
        return new static('', [], "Table [{$table}] not found");
    }

    public static function columnNotFound(string $column, string $table = ''): static
    {
        $tableInfo = $table ? " in table [{$table}]" : '';
        return new static('', [], "Column [{$column}]{$tableInfo} not found");
    }
}
