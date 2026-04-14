<?php

declare(strict_types=1);

namespace Kode\Database\Model;

/**
 * 模型事件钩子
 * 支持增删改查前后操作、观察者模式、队列、 once 事件
 */
trait ModelEvent
{
    protected static array $dispatcher = [];
    protected static array $observers = [];
    protected static array $onceEvents = [];
    protected static array $queuedEvents = [];
    protected static array $eventPriorities = [];

    /**
     * 注册模型事件
     */
    public static function registerEvent(string $event, callable $callback): void
    {
        $class = static::class;
        if (!isset(self::$dispatcher[$class])) {
            self::$dispatcher[$class] = [];
        }
        self::$dispatcher[$class][$event] = $callback;
    }

    /**
     * 注册观察者
     */
    public static function observe(Observer|string $observer): void
    {
        $class = static::class;

        if (is_string($observer)) {
            $observer = new $observer();
        }

        self::$observers[$class] = $observer;
    }

    /**
     * 获取观察者
     */
    public static function getObserver(): ?Observer
    {
        $class = static::class;
        return self::$observers[$class] ?? null;
    }

    /**
     * 触发事件
     */
    protected function fireModelEvent(string $event): mixed
    {
        $class = static::class;
        $result = true;

        // 触发注册的回调（带优先级）
        $callbacks = self::getEventCallbacks($class, $event);
        foreach ($callbacks as $priority => $callback) {
            if (is_array($callback)) {
                foreach ($callback as $cb) {
                    $result = $this->executeCallback($cb, $event);
                    if ($result === false) {
                        return false;
                    }
                }
            } else {
                $result = $this->executeCallback($callback, $event);
                if ($result === false) {
                    return false;
                }
            }
        }

        // 触发观察者方法
        $observer = self::$observers[$class] ?? null;
        if ($observer && method_exists($observer, $event)) {
            $observerResult = $observer->{$event}($this);
            if ($observerResult === false) {
                return false;
            }
        }

        return $result;
    }

    /**
     * 执行回调
     */
    protected function executeCallback(callable $callback, string $event): mixed
    {
        if ($callback instanceof \Closure) {
            return $callback($this, $event);
        }
        return call_user_func($callback, $this, $event);
    }

    /**
     * 获取事件回调（带优先级排序）
     */
    protected static function getEventCallbacks(string $class, string $event): array
    {
        $callbacks = [];

        if (isset(self::$dispatcher[$class][$event])) {
            $priority = self::$eventPriorities[$class][$event] ?? 0;
            $callbacks[$priority] = self::$dispatcher[$class][$event];
        }

        krsort($callbacks);
        return $callbacks;
    }

    /**
     * 创建前
     */
    public static function creating(callable $callback): void
    {
        static::registerEvent('creating', $callback);
    }

    /**
     * 创建后
     */
    public static function created(callable $callback): void
    {
        static::registerEvent('created', $callback);
    }

    /**
     * 更新前
     */
    public static function updating(callable $callback): void
    {
        static::registerEvent('updating', $callback);
    }

    /**
     * 更新后
     */
    public static function updated(callable $callback): void
    {
        static::registerEvent('updated', $callback);
    }

    /**
     * 删除前
     */
    public static function deleting(callable $callback): void
    {
        static::registerEvent('deleting', $callback);
    }

    /**
     * 删除后
     */
    public static function deleted(callable $callback): void
    {
        static::registerEvent('deleted', $callback);
    }

    /**
     * 保存前（插入/更新）
     */
    public static function saving(callable $callback): void
    {
        static::registerEvent('saving', $callback);
    }

    /**
     * 保存后（插入/更新）
     */
    public static function saved(callable $callback): void
    {
        static::registerEvent('saved', $callback);
    }

    /**
     * 恢复前（软删除）
     */
    public static function restoring(callable $callback): void
    {
        static::registerEvent('restoring', $callback);
    }

    /**
     * 恢复后（软删除）
     */
    public static function restored(callable $callback): void
    {
        static::registerEvent('restored', $callback);
    }

    /**
     * 强制删除前
     */
    public static function forceDeleting(callable $callback): void
    {
        static::registerEvent('forceDeleting', $callback);
    }

    /**
     * 强制删除后
     */
    public static function forceDeleted(callable $callback): void
    {
        static::registerEvent('forceDeleted', $callback);
    }

    /**
     * 注册一次性事件（执行后自动清除）
     */
    public static function once(string $event, callable $callback): void
    {
        $class = static::class;
        if (!isset(self::$onceEvents[$class])) {
            self::$onceEvents[$class] = [];
        }
        self::$onceEvents[$class][$event] = $callback;
    }

    /**
     * 触发一次性事件
     */
    protected function fireOnceEvent(string $event): mixed
    {
        $class = static::class;

        if (!isset(self::$onceEvents[$class][$event])) {
            return null;
        }

        $callback = self::$onceEvents[$class][$event];
        unset(self::$onceEvents[$class][$event]);

        return $this->executeCallback($callback, $event);
    }

    /**
     * 注册队列事件（异步执行）
     */
    public static function queue(string $event, callable $callback): void
    {
        $class = static::class;
        if (!isset(self::$queuedEvents[$class])) {
            self::$queuedEvents[$class] = [];
        }
        self::$queuedEvents[$class][$event][] = $callback;
    }

    /**
     * 触发队列事件（需要队列处理器）
     */
    protected function fireQueuedEvent(string $event): void
    {
        $class = static::class;

        if (!isset(self::$queuedEvents[$class][$event])) {
            return;
        }

        foreach (self::$queuedEvents[$class][$event] as $callback) {
            $this->executeCallback($callback, $event);
        }
    }

    /**
     * 设置事件优先级
     */
    public static function setPriority(string $event, int $priority): void
    {
        $class = static::class;
        if (!isset(self::$eventPriorities[$class])) {
            self::$eventPriorities[$class] = [];
        }
        self::$eventPriorities[$class][$event] = $priority;
    }

    /**
     * 获取所有已注册的事件
     */
    public static function getRegisteredEvents(): array
    {
        $class = static::class;
        return array_keys(self::$dispatcher[$class] ?? []);
    }

    /**
     * 检查事件是否已注册
     */
    public static function hasEvent(string $event): bool
    {
        $class = static::class;
        return isset(self::$dispatcher[$class][$event]);
    }

    /**
     * 批量注册事件
     */
    public static function registerEvents(array $events): void
    {
        foreach ($events as $event => $callback) {
            static::registerEvent($event, $callback);
        }
    }

    /**
     * 获取所有一次性事件
     */
    public static function getOnceEvents(): array
    {
        $class = static::class;
        return array_keys(self::$onceEvents[$class] ?? []);
    }

    /**
     * 获取所有队列事件
     */
    public static function getQueuedEvents(): array
    {
        $class = static::class;
        return array_keys(self::$queuedEvents[$class] ?? []);
    }

    /**
     * 清除一次性事件
     */
    public static function clearOnceEvents(?string $event = null): void
    {
        $class = static::class;
        if ($event === null) {
            unset(self::$onceEvents[$class]);
        } else {
            unset(self::$onceEvents[$class][$event]);
        }
    }

    /**
     * 清除队列事件
     */
    public static function clearQueuedEvents(?string $event = null): void
    {
        $class = static::class;
        if ($event === null) {
            unset(self::$queuedEvents[$class]);
        } else {
            unset(self::$queuedEvents[$class][$event]);
        }
    }

    /**
     * 清除事件优先级
     */
    public static function clearPriorities(?string $event = null): void
    {
        $class = static::class;
        if ($event === null) {
            unset(self::$eventPriorities[$class]);
        } else {
            unset(self::$eventPriorities[$class][$event]);
        }
    }

    /**
     * 执行保存操作前
     */
    protected function beforeSave(): bool
    {
        if ($this->exists) {
            $result = $this->fireModelEvent('updating');
            if ($result === false) {
                return false;
            }
        } else {
            $result = $this->fireModelEvent('creating');
            if ($result === false) {
                return false;
            }
        }

        $result = $this->fireModelEvent('saving');
        if ($result === false) {
            return false;
        }

        $onceResult = $this->fireOnceEvent('saving');
        if ($onceResult === false) {
            return false;
        }

        return true;
    }

    /**
     * 执行保存操作后
     */
    protected function afterSave(): void
    {
        if ($this->exists) {
            $this->fireModelEvent('updated');
        } else {
            $this->fireModelEvent('created');
        }

        $this->fireModelEvent('saved');
        $this->fireQueuedEvent('saved');
        $this->fireOnceEvent('saved');
    }

    /**
     * 执行删除操作前
     */
    protected function beforeDelete(): bool
    {
        $result = $this->fireModelEvent('deleting');
        if ($result === false) {
            return false;
        }

        $onceResult = $this->fireOnceEvent('deleting');
        if ($onceResult === false) {
            return false;
        }

        return true;
    }

    /**
     * 执行删除操作后
     */
    protected function afterDelete(): void
    {
        $this->fireModelEvent('deleted');
        $this->fireQueuedEvent('deleted');
        $this->fireOnceEvent('deleted');
    }

    /**
     * 执行恢复操作前
     */
    protected function beforeRestore(): bool
    {
        $result = $this->fireModelEvent('restoring');
        return $result !== false;
    }

    /**
     * 执行恢复操作后
     */
    protected function afterRestore(): void
    {
        $this->fireModelEvent('restored');
    }

    /**
     * 执行强制删除前
     */
    protected function beforeForceDelete(): bool
    {
        $result = $this->fireModelEvent('forceDeleting');
        return $result !== false;
    }

    /**
     * 执行强制删除后
     */
    protected function afterForceDelete(): void
    {
        $this->fireModelEvent('forceDeleted');
    }

    /**
     * 清除模型事件
     */
    public static function clearEvent(string $event): void
    {
        $class = static::class;
        unset(self::$dispatcher[$class][$event]);
    }

    /**
     * 清除所有事件
     */
    public static function clearAllEvent(): void
    {
        $class = static::class;
        unset(self::$dispatcher[$class]);
    }

    /**
     * 清除观察者
     */
    public static function clearObserver(): void
    {
        $class = static::class;
        unset(self::$observers[$class]);
    }

    /**
     * 清除所有事件相关数据
     */
    public static function clearAllEvents(): void
    {
        $class = static::class;
        unset(self::$dispatcher[$class]);
        unset(self::$observers[$class]);
        unset(self::$onceEvents[$class]);
        unset(self::$queuedEvents[$class]);
        unset(self::$eventPriorities[$class]);
    }
}
