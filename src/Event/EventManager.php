<?php

declare(strict_types=1);

namespace Kode\Database\Event;

/**
 * 事件管理器
 */
class EventManager
{
    protected static ?EventManager $instance = null;
    protected array $listeners = [];
    protected array $events = [];

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * 注册监听器
     */
    public function listen(string|array $events, ListenerInterface|string $listener): void
    {
        $events = is_array($events) ? $events : [$events];

        foreach ($events as $event) {
            if (!isset($this->listeners[$event])) {
                $this->listeners[$event] = [];
            }

            if (is_string($listener) && class_exists($listener)) {
                $listener = new $listener();
            }

            $this->listeners[$event][] = $listener;
        }
    }

    /**
     * 触发事件
     */
    public function trigger(object|string $event): void
    {
        $eventName = is_object($event) ? get_class($event) : $event;

        if (!isset($this->listeners[$eventName])) {
            return;
        }

        foreach ($this->listeners[$eventName] as $listener) {
            if ($listener instanceof ListenerInterface) {
                $listener->handle($event);
            } elseif (is_callable($listener)) {
                $listener($event);
            }
        }
    }

    /**
     * 获取监听器
     */
    public function getListeners(string $event): array
    {
        return $this->listeners[$event] ?? [];
    }

    /**
     * 清除监听器
     */
    public function clearListeners(?string $event = null): void
    {
        if ($event === null) {
            $this->listeners = [];
        } else {
            unset($this->listeners[$event]);
        }
    }

    /**
     * 判断是否有监听器
     */
    public function hasListener(string $event): bool
    {
        return !empty($this->listeners[$event]);
    }
}
