<?php

declare(strict_types=1);

namespace Kode\Database\Tests;

use Kode\Database\Connection\PdoConnection;
use PDO;
use PDOException;

/**
 * 死句柄替身：模拟「后端已被终止」的 PDO —— 事务标志还挂着，但任何语句都回吐
 * pdo_pgsql 断链时的真实形状（SQLSTATE HY000 + 驱动码 7）。
 *
 * 不调 parent::__construct()，因此不会真的连任何数据库。
 */
final class DeadHandlePdo extends PDO
{
    public int $beginCalls = 0;

    public int $rollBackCalls = 0;

    /** 置为 false 时回滚「成功」，用于验证成功路径不会误丢句柄 */
    public bool $lostOnRollBack = true;

    /** 替身没有真实事务，自行维护 inTransaction 标志 */
    public bool $inTx = true;

    public function __construct()
    {
    }

    private static function lost(): PDOException
    {
        $e = new PDOException('SQLSTATE[HY000]: General error: 7 no connection to the server');
        $e->errorInfo = ['HY000', 7, 'no connection to the server'];
        return $e;
    }

    #[\Override]
    public function inTransaction(): bool
    {
        return $this->inTx;
    }

    #[\Override]
    public function beginTransaction(): bool
    {
        $this->beginCalls++;
        throw self::lost();
    }

    #[\Override]
    public function rollBack(): bool
    {
        $this->rollBackCalls++;
        if ($this->lostOnRollBack) {
            throw self::lost();
        }
        $this->inTx = false;

        return true;
    }
}

/**
 * 测试替身：把受保护的连接故障判定 / 重连重放 / 驱动名解析暴露出来，全程不触碰真实 PDO 连接。
 */
final class RetryProbe extends PdoConnection
{
    /**
     * 句柄队列：每次 ensureConnected() 弹出一个，用于按「死句柄 → 重连后的新句柄」
     * 的顺序喂给被测代码，全程不触碰真实数据库。
     *
     * @var list<PDO>
     */
    public array $handleQueue = [];

    public function __construct(array $config = [])
    {
        parent::__construct($config);
    }

    #[\Override]
    protected function ensureConnected(): PDO
    {
        if ($this->handleQueue !== []) {
            $this->pdo = array_shift($this->handleQueue);
        }

        return parent::ensureConnected();
    }

    public static function failure(PDOException $e, string $driver = ''): bool
    {
        return self::isConnectionFailure($e, $driver);
    }

    public function forceLevel(int $level): void
    {
        $this->transactionLevel = $level;
    }

    public function runRetry(callable $execute): mixed
    {
        return $this->retryOnce($execute);
    }

    public function resolvedDriver(): string
    {
        return $this->pdoDriver();
    }

    public function inject(?PDO $pdo): void
    {
        $this->pdo = $pdo;
    }

    public function handle(): ?PDO
    {
        return $this->pdo;
    }

    public function level(): int
    {
        return $this->transactionLevel;
    }
}
