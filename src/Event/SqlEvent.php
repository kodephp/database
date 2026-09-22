<?php

declare(strict_types=1);

namespace Kode\Database\Event;

/**
 * SQL 事件
 *
 * 由执行器的观测切面（{@see \Kode\Database\Connection\QueryObservation}）在每条 SQL 跑完后派发，
 * 是「谁关心查询耗时/失败」的扩展点：
 * 慢查询日志、指标打点、N+1 探测都挂在这里，不需要在执行器里塞 if。
 */
class SqlEvent
{
    protected string $sql = '';
    protected array $bindings = [];
    protected float $time = 0;
    protected ?string $connection = null;
    protected float $duration = 0.0;
    protected ?\Throwable $error = null;

    /**
     * @param float $duration 执行耗时（秒）。0 表示调用方未测量。
     * @param \Throwable|null $error 执行抛出的异常；成功为 null
     */
    public function __construct(
        string $sql,
        array $bindings = [],
        ?string $connection = null,
        float $duration = 0.0,
        ?\Throwable $error = null,
    ) {
        $this->sql = $sql;
        $this->bindings = $bindings;
        $this->connection = $connection;
        $this->duration = $duration;
        $this->error = $error;
        $this->time = microtime(true);
    }

    public function getSql(): string
    {
        return $this->sql;
    }

    public function getBindings(): array
    {
        return $this->bindings;
    }

    public function getConnection(): ?string
    {
        return $this->connection;
    }

    /**
     * 事件创建时刻（microtime(true)），不是耗时。
     *
     * 想按「慢不慢」决策请用 getDuration()；本值历史上就被当成耗时用，故保留不改名。
     */
    public function getTime(): float
    {
        return $this->time;
    }

    public function getDuration(): float
    {
        return $this->duration;
    }

    public function getError(): ?\Throwable
    {
        return $this->error;
    }

    public function failed(): bool
    {
        return $this->error !== null;
    }

    /**
     * 把占位符替换成字面量，**仅供人看**（调试输出、日志）。
     *
     * 不要拿它去执行 SQL：这里既没按目标方言转义，也没法还原绑定顺序。
     * 参数值原样出现在结果里，等于把 PII 抄进日志，所以本方法不参与默认日志路径。
     */
    public function getFormattedSql(): string
    {
        if ($this->bindings === []) {
            return $this->sql;
        }

        // 用回调而不是 preg_replace($sql, $value)：替换串里的 $0 / $1 / \1 会被当作
        // 反向引用展开，参数值一旦含这类片段，输出的 SQL 就是错的（且 PHP 会告警）。
        $i = 0;
        $bindings = array_values($this->bindings);

        return (string) preg_replace_callback(
            '/\?/',
            static function () use (&$i, $bindings): string {
                if ($i >= count($bindings)) {
                    return '?';
                }
                $binding = $bindings[$i++];

                return match (true) {
                    is_bool($binding) => $binding ? '1' : '0',
                    is_int($binding) || is_float($binding) => (string) $binding,
                    $binding === null => 'NULL',
                    default => "'" . addslashes((string) $binding) . "'",
                };
            },
            $this->sql,
        );
    }
}
