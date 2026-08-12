<?php

declare(strict_types=1);

namespace Kode\Database\Config;

/**
 * 数据库驱动枚举（PHP 8.1+ enum）
 */
enum Driver: string
{
    case Mysql = 'mysql';
    case Postgres = 'pgsql';
    case Sqlite = 'sqlite';
    case SqlServer = 'sqlsrv';
    case Oracle = 'oracle';

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
     * 从字符串安全解析，未知值回退为 Mysql
     */
    public static function fromString(string $driver): self
    {
        return self::tryFrom(strtolower($driver)) ?? self::Mysql;
    }
}
