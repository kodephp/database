<?php

declare(strict_types=1);

namespace Kode\Database\Config;

/**
 * 数据库驱动枚举（PHP 8.1+ enum）
 *
 * 连接配置中存在两个容易混淆的维度：
 *  - 连接器（ORM 执行器）选择器：laravel / thinkphp / symfony / hyperf / pdo
 *  - 数据库类型（方言）：mysql / pgsql / sqlite / sqlsrv / oracle
 *
 * 本枚举提供从混合配置中解析这两个维度的安全方法，消除 "driver 字段双语义" 导致的
 * "内置 PDO 执行器只能顺滑支持 mysql" 的缺陷。
 */
enum Driver: string
{
    case Mysql = 'mysql';
    case Postgres = 'pgsql';
    case Sqlite = 'sqlite';
    case SqlServer = 'sqlsrv';
    case Oracle = 'oracle';

    /** 受支持的 ORM 连接器（执行器）选择器 */
    public const ORM_CONNECTORS = ['laravel', 'thinkphp', 'symfony', 'hyperf', 'pdo'];

    /** 数据库类型别名 -> 标准值 */
    private const ALIASES = [
        'postgres' => self::Postgres,
        'postgresql' => self::Postgres,
        'sqlserver' => self::SqlServer,
        'mssql' => self::SqlServer,
        'dblib' => self::SqlServer,
    ];

    /**
     * 对应 PDO DSN 驱动名
     */
    public function pdoDriver(): string
    {
        return match ($this) {
            self::Mysql => 'mysql',
            self::Postgres => 'pgsql',
            self::Sqlite => 'sqlite',
            self::SqlServer => 'sqlsrv',
            self::Oracle => 'oci',
        };
    }

    /**
     * 默认端口
     */
    public function defaultPort(): int
    {
        return match ($this) {
            self::Mysql => 3306,
            self::Postgres => 5432,
            self::Sqlite => 0,
            self::SqlServer => 1433,
            self::Oracle => 1521,
        };
    }

    /**
     * 从字符串安全解析数据库类型，未知值回退为 Mysql
     */
    public static function fromString(string $driver): self
    {
        $driver = strtolower($driver);

        if (isset(self::ALIASES[$driver])) {
            return self::ALIASES[$driver];
        }

        return self::tryFrom($driver) ?? self::Mysql;
    }

    /**
     * 从连接配置中解析「数据库类型」（方言）。
     *
     * 约定：
     *  - 若 driver 是受支持的 ORM 连接器名（laravel/thinkphp/symfony/hyperf/pdo），
     *    则它是执行器选择器，数据库类型由 database_driver 显式给出，否则回退 mysql。
     *  - 否则 driver 即视为数据库类型（mysql/pgsql/sqlite/sqlsrv/oracle）或其别名。
     */
    public static function dbTypeFromConfig(array $config): self
    {
        $driver = strtolower((string) ($config['driver'] ?? 'mysql'));

        if (in_array($driver, self::ORM_CONNECTORS, true)) {
            return self::fromString($config['database_driver'] ?? 'mysql');
        }

        return self::fromString($driver);
    }

    /**
     * 从连接配置中解析「连接执行器（ORM 连接器）」。
     *
     * 约定：
     *  - 显式 connector 字段优先。
     *  - 否则若 driver 是 ORM 连接器名，则它即连接器。
     *  - 否则 driver 视为数据库类型，连接器自动探测已安装的 ORM，未安装则回退内置 pdo 执行器。
     */
    public static function connectorFromConfig(array $config): string
    {
        $connector = $config['connector'] ?? null;
        if ($connector !== null && in_array(strtolower((string) $connector), self::ORM_CONNECTORS, true)) {
            return strtolower((string) $connector);
        }

        $driver = strtolower((string) ($config['driver'] ?? 'pdo'));
        if (in_array($driver, self::ORM_CONNECTORS, true)) {
            return $driver;
        }

        return self::detectInstalledConnector();
    }

    /**
     * 探测当前环境已安装的 ORM 连接器；均未安装时回退内置 pdo 执行器
     */
    public static function detectInstalledConnector(): string
    {
        if (class_exists(\Illuminate\Database\Capsule\Manager::class)
            || class_exists(\Illuminate\Support\Facades\DB::class)) {
            return 'laravel';
        }

        if (class_exists(\think\facade\Db::class)) {
            return 'thinkphp';
        }

        if (class_exists(\Doctrine\DBAL\DriverManager::class)) {
            return 'symfony';
        }

        if (class_exists(\Hyperf\DbConnection\Db::class)) {
            return 'hyperf';
        }

        return 'pdo';
    }
}
