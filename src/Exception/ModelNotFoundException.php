<?php

declare(strict_types=1);

namespace Kode\Database\Exception;

/**
 * 模型未找到异常
 */
class ModelNotFoundException extends DatabaseException
{
    protected string $model = '';

    public function __construct(string $model = '', ?string $message = null)
    {
        $this->model = $model;
        $message = $message ?? ($model ? "Model [{$model}] not found" : 'Record not found');
        parent::__construct($message);
    }

    public function getModel(): string
    {
        return $this->model;
    }

    public static function make(string $model = ''): static
    {
        return new static($model);
    }

    public static function notFound(string $model = ''): static
    {
        return new static($model, "{$model} not found");
    }
}
