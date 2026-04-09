<?php

declare(strict_types=1);

namespace Kode\Database\Event;

/**
 * SQL 查询监听器
 */
class SqlListener implements ListenerInterface
{
    protected array $sqls = [];
    protected bool $enabled = true;

    public function handle(object $event): void
    {
        if (!$this->enabled) {
            return;
        }

        if ($event instanceof SqlEvent) {
            $this->sqls[] = [
                'sql' => $event->getSql(),
                'bindings' => $event->getBindings(),
                'time' => $event->getTime(),
                'formatted_sql' => $event->getFormattedSql(),
            ];
        }
    }

    public function listen(): array|string
    {
        return SqlEvent::class;
    }

    public function getSqls(): array
    {
        return $this->sqls;
    }

    public function getLastSql(): ?array
    {
        return $this->sqls[count($this->sqls) - 1] ?? null;
    }

    public function clear(): void
    {
        $this->sqls = [];
    }

    public function enable(): void
    {
        $this->enabled = true;
    }

    public function disable(): void
    {
        $this->enabled = false;
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }
}
