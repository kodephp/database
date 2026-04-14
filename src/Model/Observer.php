<?php

declare(strict_types=1);

namespace Kode\Database\Model;

/**
 * 模型观察者
 * 用于批量处理模型事件
 */
abstract class Observer
{
    protected static array $globalObservers = [];

    /**
     * 创建前
     */
    public function creating(Model $model): void
    {
    }

    /**
     * 创建后
     */
    public function created(Model $model): void
    {
    }

    /**
     * 更新前
     */
    public function updating(Model $model): void
    {
    }

    /**
     * 更新后
     */
    public function updated(Model $model): void
    {
    }

    /**
     * 保存前
     */
    public function saving(Model $model): void
    {
    }

    /**
     * 保存后
     */
    public function saved(Model $model): void
    {
    }

    /**
     * 删除前
     */
    public function deleting(Model $model): void
    {
    }

    /**
     * 删除后
     */
    public function deleted(Model $model): void
    {
    }

    /**
     * 恢复前
     */
    public function restoring(Model $model): void
    {
    }

    /**
     * 恢复后
     */
    public function restored(Model $model): void
    {
    }

    /**
     * 强制删除前
     */
    public function forceDeleting(Model $model): void
    {
    }

    /**
     * 强制删除后
     */
    public function forceDeleted(Model $model): void
    {
    }

    /**
     * 获取观察者监听的模型类
     */
    public static function getModel(): string
    {
        return '';
    }

    /**
     * 注册全局观察者（所有模型触发）
     */
    public static function registerGlobal(callable $callback, string $event = '*'): void
    {
        $class = static::class;
        if (!isset(self::$globalObservers[$class])) {
            self::$globalObservers[$class] = [];
        }
        self::$globalObservers[$class][$event] = $callback;
    }

    /**
     * 获取全局观察者
     */
    public static function getGlobalObservers(): array
    {
        return self::$globalObservers;
    }

    /**
     * 清除全局观察者
     */
    public static function clearGlobalObservers(): void
    {
        $class = static::class;
        unset(self::$globalObservers[$class]);
    }

    /**
     * 获取观察者名称
     */
    public function getName(): string
    {
        return static::class;
    }

    /**
     * 检查是否监听指定事件
     */
    public function hasEvent(string $event): bool
    {
        return method_exists($this, $event);
    }

    /**
     * 获取所有监听的事件
     */
    public function getEvents(): array
    {
        $events = [
            'creating', 'created', 'updating', 'updated',
            'saving', 'saved', 'deleting', 'deleted',
            'restoring', 'restored', 'forceDeleting', 'forceDeleted'
        ];

        return array_filter($events, fn($event) => method_exists($this, $event));
    }
}

/**
 * 观察者管理类
 * 用于批量管理观察者
 */
class ObserverManager
{
    protected static array $observers = [];
    protected static array $globalObservers = [];

    /**
     * 注册观察者
     */
    public static function register(string $modelClass, Observer|string $observer): void
    {
        if (is_string($observer)) {
            $observer = new $observer();
        }

        self::$observers[$modelClass] = $observer;
    }

    /**
     * 获取观察者
     */
    public static function get(string $modelClass): ?Observer
    {
        return self::$observers[$modelClass] ?? null;
    }

    /**
     * 注册全局观察者
     */
    public static function registerGlobal(callable $callback, string $event = '*'): void
    {
        self::$globalObservers[$event][] = $callback;
    }

    /**
     * 获取全局观察者
     */
    public static function getGlobalObservers(string $event = '*'): array
    {
        $callbacks = self::$globalObservers[$event] ?? [];
        if ($event !== '*' && isset(self::$globalObservers['*'])) {
            $callbacks = array_merge(self::$globalObservers['*'], $callbacks);
        }
        return $callbacks;
    }

    /**
     * 触发观察者事件
     */
    public static function fire(string $modelClass, string $event, Model $model): mixed
    {
        $result = true;

        foreach (self::getGlobalObservers($event) as $callback) {
            $callbackResult = $callback($model, $event);
            if ($callbackResult === false) {
                $result = false;
            }
        }

        $observer = self::get($modelClass);
        if ($observer && method_exists($observer, $event)) {
            $observerResult = $observer->{$event}($model);
            if ($observerResult === false) {
                $result = false;
            }
        }

        return $result;
    }

    /**
     * 清除观察者
     */
    public static function unregister(string $modelClass): void
    {
        unset(self::$observers[$modelClass]);
    }

    /**
     * 清除所有观察者
     */
    public static function clear(): void
    {
        self::$observers = [];
        self::$globalObservers = [];
    }

    /**
     * 检查观察者是否存在
     */
    public static function has(string $modelClass): bool
    {
        return isset(self::$observers[$modelClass]);
    }
}
