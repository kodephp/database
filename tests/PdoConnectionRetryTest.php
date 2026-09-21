<?php

declare(strict_types=1);

namespace Kode\Database\Tests;

use Kode\Database\Connection\PdoConnection;
use PHPUnit\Framework\TestCase;

final class PdoConnectionRetryTest extends TestCase
{
    /**
     * 仅暴露受保护的连接故障判定，不触碰真实 PDO 连接
     */
    private static function classify(\PDOException $e): bool
    {
        $probe = new class(['driver' => 'pdo']) extends PdoConnection {
            public static function failure(\PDOException $e): bool
            {
                return self::isConnectionFailure($e);
            }
        };
        return $probe::failure($e);
    }

    private static function exception(string $sqlState, int|string $driverCode = 0): \PDOException
    {
        $e = new \PDOException('test');
        $e->errorInfo = [$sqlState, $driverCode, 'message'];
        return $e;
    }

    public function testConnectionClassSqlStateIsRetryable(): void
    {
        self::assertTrue(self::classify(self::exception('08006', '08006')));
        self::assertTrue(self::classify(self::exception('08S01', 2006)));
    }

    public function testMysqlGoneAwayDriverCodeIsRetryable(): void
    {
        self::assertTrue(self::classify(self::exception('HY000', 2006)));
        self::assertTrue(self::classify(self::exception('HY000', 2013)));
    }

    public function testTimeoutIsRetryable(): void
    {
        self::assertTrue(self::classify(self::exception('HYT00', 0)));
    }

    public function testConstraintViolationIsNotRetryable(): void
    {
        self::assertFalse(self::classify(self::exception('23505', '23505')));
        self::assertFalse(self::classify(self::exception('23000', 1062)));
    }

    public function testSyntaxErrorIsNotRetryable(): void
    {
        self::assertFalse(self::classify(self::exception('42601', '42601')));
        self::assertFalse(self::classify(self::exception('42000', 1064)));
    }
}
