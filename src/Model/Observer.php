<?php

declare(strict_types=1);

namespace Kode\Database\Model;

/**
 * 模型观察者
 * 用于批量处理模型事件
 */
abstract class Observer
{
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
}
