<?php

declare(strict_types=1);

namespace Kode\Database\Exception;

use Throwable;

/**
 * 数据库异常基类
 */
class DatabaseException extends \RuntimeException
{
    protected string $sql = '';
    protected array $bindings = [];

    public function __construct(
        string $message = '',
        int $code = 0,
        ?Throwable $previous = null,
        string $sql = '',
        array $bindings = []
    ) {
        parent::__construct($message, $code, $previous);
        $this->sql = $sql;
        $this->bindings = $bindings;
    }

    public function getSql(): string
    {
        return $this->sql;
    }

    public function getBindings(): array
    {
        return $this->bindings;
    }
}
