<?php

declare(strict_types=1);

namespace Kode\Database\Event;

/**
 * 事件管理器
 *
 * 进程级单例：常驻 worker 里监听器在启动期注册一次即可。
 * 观测切面（{@see \Kode\Database\Connection\QueryObservation}）每条 SQL 都会问一次
 * {@see self::hasAny()}，返回 false 时连事件对象都不构造 —— 无人订阅就等于零成本。
 */
class EventManager
{
    protected static ?EventManager $instance = null;
    /** @var array<string, list<ListenerInterface|callable>> */
    protected array $listeners = [];

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * 丢弃单例（连已注册的监听器一起）。
     *
     * 常驻进程一般不该调它；测试与「配置热改后重建监听」需要干净状态时用。
     */
    public static function resetInstance(): void
    {
        self::$instance = null;
    }

    /**
     * 注册监听器。
     *
     * $events 传 null 时，从 ListenerInterface::listen() 自动取事件名
     * （此前该接口方法全程没人读，注册方要么重复写一遍类名、要么漏写导致监听器永不触发）。
     *
     * @param string|array<string>|null $events
     */
    public function listen(string|array|null $events, ListenerInterface|callable|string $listener): void
    {
        if ($events === null) {
            $events = self::declaredEvents($listener);
        }
        if ($events === '') {
            $events = [];
        }
        $events = is_array($events) ? $events : [$events];

        if ($events === []) {
            throw new \InvalidArgumentException('未指定监听的事件名（传 null 时监听器必须实现 ListenerInterface::listen()）');
        }

        foreach ($events as $event) {
            if (!isset($this->listeners[$event])) {
                $this->listeners[$event] = [];
            }

            if (is_string($listener)) {
                if (!class_exists($listener)) {
                    throw new \InvalidArgumentException("监听器类 [{$listener}] 不存在");
                }
                $listener = new $listener();
            }

            $this->listeners[$event][] = $listener;
        }
    }

    /**
     * 触发事件。
     *
     * @param object $event 事件对象；事件名即其类名
     */
    public function trigger(object $event): void
    {
        $eventName = $event::class;

        foreach ($this->listeners[$eventName] ?? [] as $listener) {
            if ($listener instanceof ListenerInterface) {
                $listener->handle($event);
            } elseif (is_callable($listener)) {
                $listener($event);
            }
        }
    }

    /**
     * 是否注册过任何监听器（执行器据此决定要不要做观测）。
     */
    public function hasAny(): bool
    {
        foreach ($this->listeners as $group) {
            if ($group !== []) {
                return true;
            }
        }

        return false;
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

    /**
     * @param ListenerInterface|callable|string $listener
     * @return array<string>
     */
    private static function declaredEvents(ListenerInterface|callable|string $listener): array
    {
        if (is_string($listener) && class_exists($listener)) {
            $listener = new $listener();
        }

        return $listener instanceof ListenerInterface ? (array) $listener->listen() : [];
    }
}
