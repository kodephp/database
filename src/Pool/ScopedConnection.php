<?php

declare(strict_types=1);

namespace Kode\Database\Pool;

/**
 * 作用域连接（RAII）
 *
 * 包裹一个从连接池取到的连接；作用域结束（对象销毁）时自动归还到池中，
 * 调用方无需手动 release —— 对齐 webman / Hyperf「请求结束自动回收连接」的体验。
 *
 * 注意：归还是幂等的（重复 release 安全），但一旦归还，本对象持有的连接即不可再用，
 * 请只在回调/局部作用域内使用，不要逃逸到作用域之外。
 *
 * @example
 * // 方式一：闭包作用域（推荐）
 * $result = PoolManager::scoped(function ($conn) {
 *     return $conn->select('SELECT * FROM users WHERE id = ?', [1]);
 * });
 *
 * // 方式二：显式作用域对象
 * $scoped = PoolManager::getScopedConnection();
 * try {
 *     $rows = $scoped->get()->select('SELECT 1');
 * } finally {
 *     $scoped->release(); // 也可依赖 __destruct 自动归还
 * }
 */
final class ScopedConnection
{
    private mixed $connection;
    private ?string $driver;
    private bool $released = false;

    public function __construct(mixed $connection, ?string $driver = null)
    {
        $this->connection = $connection;
        $this->driver = $driver;
    }

    /**
     * 获取底层连接
     */
    public function get(): mixed
    {
        if ($this->released) {
            throw new \RuntimeException('连接已在作用域结束时归还，不可再使用');
        }

        return $this->connection;
    }

    /**
     * 主动归还连接到池中（幂等）
     */
    public function release(): void
    {
        if ($this->released) {
            return;
        }

        $this->released = true;
        PoolManager::releaseConnection($this->connection, $this->driver);
    }

    /**
     * 作用域结束自动归还（RAII）
     */
    public function __destruct()
    {
        $this->release();
    }
}
