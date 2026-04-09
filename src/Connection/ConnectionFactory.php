<?php

declare(strict_types=1);

namespace Kode\Database\Connection;

/**
 * 数据库连接工厂
 */
class ConnectionFactory
{
    protected array $connectors = [];

    public function __construct()
    {
        $this->registerDefaultConnectors();
    }

    /**
     * 注册默认连接器
     */
    protected function registerDefaultConnectors(): void
    {
        $this->connectors = [
            'laravel' => new LaravelConnector(),
            'thinkphp' => new ThinkPHPConnector(),
            'default' => new LaravelConnector(),
        ];
    }

    /**
     * 注册连接器
     *
     * @param string $name 连接器名称
     * @param ConnectorInterface $connector 连接器实例
     * @return self
     */
    public function register(string $name, ConnectorInterface $connector): self
    {
        $this->connectors[$name] = $connector;
        return $this;
    }

    /**
     * 创建连接
     *
     * @param array $config 配置 ['driver' => 'laravel', 'config' => [...]]
     * @return mixed
     */
    public function make(array $config): mixed
    {
        $driver = $config['driver'] ?? 'default';
        $connector = $this->connectors[$driver] ?? $this->connectors['default'];

        return $connector->connect($config['config'] ?? $config);
    }

    /**
     * 获取已注册的连接器
     *
     * @return array
     */
    public function getConnectors(): array
    {
        return $this->connectors;
    }
}
