<?php

declare(strict_types=1);

namespace Kode\Database\Pool;

use Kode\Database\Connection\ConnectorInterface;
use Kode\Database\Exception\ConnectionException;

/**
 * 连接池实现
 */
class ConnectionPool implements PoolInterface
{
    protected ConnectorInterface $connector;
    protected array $config;
    protected array $connections = [];
    protected int $maxConnections = 10;
    protected int $minConnections = 1;

    public function __construct(array $config, string $driver = 'default')
    {
        $this->config = $config;
        $this->maxConnections = $config['pool']['max'] ?? 10;
        $this->minConnections = $config['pool']['min'] ?? 1;
        $this->connector = $this->createConnector($driver);
    }

    protected function createConnector(string $driver): ConnectorInterface
    {
        $connectorClass = match ($driver) {
            'laravel' => \Kode\Database\Connection\LaravelConnector::class,
            'thinkphp' => \Kode\Database\Connection\ThinkPHPConnector::class,
            default => \Kode\Database\Connection\LaravelConnector::class,
        };

        return new $connectorClass();
    }

    public function get(): mixed
    {
        if (!empty($this->connections)) {
            return array_pop($this->connections);
        }

        return $this->connector->connect($this->config);
    }

    public function release(mixed $connection): void
    {
        if (count($this->connections) < $this->maxConnections) {
            if ($this->connector->isConnected($connection)) {
                $this->connections[] = $connection;
                return;
            }
        }

        $this->connector->disconnect($connection);
    }

    public function getConnector(): ConnectorInterface
    {
        return $this->connector;
    }

    public function getConfig(): array
    {
        return $this->config;
    }

    public function close(): void
    {
        foreach ($this->connections as $connection) {
            $this->connector->disconnect($connection);
        }
        $this->connections = [];
    }
}
