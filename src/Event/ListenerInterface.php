<?php

declare(strict_types=1);

namespace Kode\Database\Event;

/**
 * 监听器接口
 */
interface ListenerInterface
{
    /**
     * 处理事件
     */
    public function handle(object $event): void;

    /**
     * 监听的事件
     */
    public function listen(): array|string;
}
