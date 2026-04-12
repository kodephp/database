<?php

declare(strict_types=1);

namespace Kode\Database\Exception;

/**
 * 数据库连接异常
 */
class ConnectionException extends DatabaseException
{
    protected string $connectionName = '';
    protected string $host = '';
    protected int $port = 0;

    public function __construct(
        string $connectionName = '',
        ?string $message = null,
        int $code = 0,
        ?\Throwable $previous = null,
        string $host = '',
        int $port = 0
    ) {
        $this->connectionName = $connectionName;
        $this->host = $host;
        $this->port = $port;

        if ($message === null) {
            $location = $host ? "{$host}:{$port}" : $connectionName;
            $message = "Cannot connect to database [{$location}]";
        }

        parent::__construct($message, $code, $previous);
    }

    public function getConnectionName(): string
    {
        return $this->connectionName;
    }

    public function getHost(): string
    {
        return $this->host;
    }

    public function getPort(): int
    {
        return $this->port;
    }

    public static function make(string $connectionName = 'default', ?string $message = null): static
    {
        return new static($connectionName, $message, 0, null, '', 0);
    }

    public static function cannotConnect(string $connectionName = 'default', string $host = '', int $port = 0): static
    {
        return new static($connectionName, null, 0, null, $host, $port);
    }

    public static function timeout(string $connectionName = 'default'): static
    {
        return new static($connectionName, "Connection timeout [{$connectionName}]");
    }

    public static function refused(string $connectionName = 'default', string $host = '', int $port = 0): static
    {
        return new static($connectionName, null, 0, null, $host, $port);
    }
}
