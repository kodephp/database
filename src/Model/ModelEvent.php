<?php

declare(strict_types=1);

namespace Kode\Database\Model;

/**
 * 模型事件钩子
 * 支持增删改查前后操作
 */
trait ModelEvent
{
    protected static array $dispatcher = [];

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
     * 触发事件
     */
    protected function fireModelEvent(string $event): mixed
    {
        $class = static::class;

        if (!isset(self::$dispatcher[$class][$event])) {
            return true;
        }

        $callback = self::$dispatcher[$class][$event];

        if ($callback instanceof \Closure) {
            return $callback($this);
        }

        return call_user_func($callback, $this);
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
        return $result !== false;
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
    }

    /**
     * 执行删除操作前
     */
    protected function beforeDelete(): bool
    {
        $result = $this->fireModelEvent('deleting');
        return $result !== false;
    }

    /**
     * 执行删除操作后
     */
    protected function afterDelete(): void
    {
        $this->fireModelEvent('deleted');
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
}
