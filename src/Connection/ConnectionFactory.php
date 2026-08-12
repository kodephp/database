<?php

declare(strict_types=1);

namespace Kode\Database\Connection;

use Kode\Database\Config\Driver;

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
     *
     * 支持 Laravel / ThinkPHP / Symfony(Doctrine) / Hyperf 四大 ORM，
     * 开发者安装哪一个就注册哪一个对应驱动（其余自动回退到内置 PDO 执行器）。
     */
    protected function registerDefaultConnectors(): void
    {
        $this->connectors = [
            'laravel' => new LaravelConnector(),
            'thinkphp' => new ThinkPHPConnector(),
            'symfony' => new SymfonyConnector(),
            'hyperf' => new HyperfConnector(),
            'pdo' => new PdoConnector(),
            'default' => new PdoConnector(),
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
     * 兼容两种配置写法：
     *  1. 嵌套式（适配 ORM 连接管理器）：['driver' => 'laravel', 'config' => ['driver' => 'mysql', ...]]
     *  2. 扁平式：['driver' => 'mysql', 'host' => ..., ...] 或 ['connector' => 'pdo', 'driver' => 'pgsql', ...]
     *
     * 其中 driver 兼具两种语义：ORM 连接器选择器（laravel/thinkphp/symfony/hyperf/pdo）
     * 与数据库类型（mysql/pgsql/sqlite/sqlsrv/oracle），由 Driver 工具方法统一解析。
     *
     * @param array $config 连接配置
     * @return mixed
     */
    public function make(array $config): mixed
    {
        // 嵌套式：交给对应连接器自行处理（Laravel/Symfony 等连接管理器通常接收内层 config）
        if (isset($config['config']) && is_array($config['config'])) {
            $connectorName = Driver::connectorFromConfig($config);
            $connector = $this->connectors[$connectorName] ?? $this->connectors['default'];
            return $connector->connect($config['config']);
        }

        $connectorName = Driver::connectorFromConfig($config);
        $connector = $this->connectors[$connectorName] ?? $this->connectors['default'];

        // 让内置 PDO 执行器能正确识别数据库类型（driver 字段可能是连接器名或数据库类型）
        $config = $this->normalizeForPdo($connectorName, $config);

        return $connector->connect($config);
    }

    /**
     * 为内置 PDO 执行器规范化数据库类型标识
     *
     * 当使用 pdo 执行器时，确保 config 中携带正确的 DB 类型（mysql/pgsql/sqlite/sqlsrv/oracle），
     * 使 PdoConnection::buildDsn 能生成正确 DSN，从而支持所有数据库。
     */
    protected function normalizeForPdo(string $connectorName, array $config): array
    {
        if ($connectorName !== 'pdo') {
            return $config;
        }

        $dbType = Driver::dbTypeFromConfig($config)->value;
        $config['database_driver'] = $dbType;

        // 当 driver 本身并非数据库类型（例如显式 driver=pdo）时，补充 pdo_driver 供 DSN 使用
        $raw = strtolower((string) ($config['driver'] ?? ''));
        if (!in_array($raw, ['mysql', 'pgsql', 'postgres', 'postgresql', 'sqlite', 'sqlsrv', 'sqlserver', 'oracle'], true)) {
            $config['pdo_driver'] = $dbType;
        }

        return $config;
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
