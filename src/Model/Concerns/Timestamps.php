<?php

declare(strict_types=1);

namespace Kode\Database\Model\Concerns;

trait Timestamps
{
    protected string $createdAtField = 'created_at';

    protected string $updatedAtField = 'updated_at';

    protected bool $timestamps = true;

    protected string $dateFormat = 'Y-m-d H:i:s';

    public function getCreatedAtField(): string
    {
        return $this->createdAtField;
    }

    public function getUpdatedAtField(): string
    {
        return $this->updatedAtField;
    }

    public function getDateFormat(): string
    {
        return $this->dateFormat;
    }

    protected function setTimestamps(): void
    {
        if ($this->timestamps) {
            $now = date($this->dateFormat);

            if (!$this->exists) {
                $this->{$this->createdAtField} = $now;
            }

            $this->{$this->updatedAtField} = $now;
        }
    }

    protected function fromDateTime(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if ($value instanceof \DateTimeInterface) {
            return $value->format($this->dateFormat);
        }

        return (string) $value;
    }
}
