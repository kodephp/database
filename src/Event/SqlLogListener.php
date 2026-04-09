<?php

declare(strict_types=1);

namespace Kode\Database\Event;

/**
 * SQL 日志监听器
 */
class SqlLogListener implements ListenerInterface
{
    protected array $logs = [];

    public function handle(object $event): void
    {
        if ($event instanceof SqlEvent) {
            $this->logs[] = [
                'sql' => $event->getFormattedSql(),
                'time' => date('Y-m-d H:i:s'),
            ];
        }
    }

    public function listen(): array|string
    {
        return SqlEvent::class;
    }

    public function getLogs(): array
    {
        return $this->logs;
    }

    public function clear(): void
    {
        $this->logs = [];
    }
}
