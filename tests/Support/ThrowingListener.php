<?php

declare(strict_types=1);

namespace Kode\Database\Tests\Support;

use Kode\Database\Event\ListenerInterface;
use Kode\Database\Event\SqlEvent;
use RuntimeException;

/**
 * 一被调用就抛异常的监听器，用来验证观测异常不外溢。
 */
final class ThrowingListener implements ListenerInterface
{
    public static int $hits = 0;

    public static function reset(): void
    {
        self::$hits = 0;
    }

    #[\Override]
    public function handle(object $event): void
    {
        self::$hits++;

        throw new RuntimeException('监听器写坏了');
    }

    #[\Override]
    public function listen(): array|string
    {
        return SqlEvent::class;
    }
}
