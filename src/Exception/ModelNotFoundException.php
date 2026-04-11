<?php

declare(strict_types=1);

namespace Kode\Database\Exception;

class ModelNotFoundException extends DatabaseException
{
    protected string $model;

    public function __construct(string $model = '', string $message = 'Model not found')
    {
        $this->model = $model;
        parent::__construct($message);
    }

    public function getModel(): string
    {
        return $this->model;
    }
}
