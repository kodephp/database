<?php

declare(strict_types=1);

namespace Kode\Database\Config;

/**
 * 数据库连接配置（不可变值对象，PHP 8.3 风格）
 *
 * 通过数组构造，提供类型安全的访问方式，避免散落的配置数组。
 */
final readonly class DatabaseConfig
{
    public Driver $driver;
    public string $host;
    public int $port;
    public string $database;
    public ?string $username;
    public ?string $password;
    public string $charset;
    public string $collation;
    public string $prefix;
    public bool $pool;
    public array $options;

    /**
     * @param array<string, mixed> $config
     */
    public function __construct(array $config)
    {
        $this->driver = Driver::fromString($config['driver'] ?? 'mysql');
        $this->host = $config['host'] ?? '127.0.0.1';
        $this->port = (int) ($config['port'] ?? $this->driver->defaultPort());
        $this->database = $config['database'] ?? '';
        $this->username = $config['username'] ?? $config['user'] ?? null;
        $this->password = $config['password'] ?? $config['pass'] ?? null;
        $this->charset = $config['charset'] ?? 'utf8mb4';
        $this->collation = $config['collation'] ?? 'utf8mb4_unicode_ci';
        $this->prefix = $config['prefix'] ?? '';
        $this->pool = (bool) ($config['pool'] ?? false);
        $this->options = $config['options'] ?? [];
    }

    /**
     * 返回底层 PDO/连接驱动名
     */
    public function driverName(): string
    {
        return $this->driver->value;
    }

    /**
     * 克隆并切换数据库（只读对象，故返回新实例）
     */
    public function withDatabase(string $database): self
    {
        return new self([
            'driver' => $this->driver->value,
            'host' => $this->host,
            'port' => $this->port,
            'database' => $database,
            'username' => $this->username,
            'password' => $this->password,
            'charset' => $this->charset,
            'collation' => $this->collation,
            'prefix' => $this->prefix,
            'pool' => $this->pool,
            'options' => $this->options,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'driver' => $this->driver->value,
            'host' => $this->host,
            'port' => $this->port,
            'database' => $this->database,
            'username' => $this->username,
            'password' => $this->password,
            'charset' => $this->charset,
            'collation' => $this->collation,
            'prefix' => $this->prefix,
            'pool' => $this->pool,
            'options' => $this->options,
        ];
    }
}
