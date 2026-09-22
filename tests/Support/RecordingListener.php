<?php

declare(strict_types=1);

namespace Kode\Database\Tests\Support;

use Kode\Database\Event\ListenerInterface;
use Kode\Database\Event\SqlEvent;

/**
 * 把收到的事件攒起来，顺带验证 ListenerInterface::listen() 会被自动采用。
 */
final class RecordingListener implements ListenerInterface
{
    /** @var list<SqlEvent> */
    public static array $events = [];

    #[\Override]
    public function handle(object $event): void
    {
        self::$events[] = $event;
    }

    #[\Override]
    public function listen(): array|string
    {
        return SqlEvent::class;
    }
}
