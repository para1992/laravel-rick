<?php

declare(strict_types=1);

namespace Rick\Laravel\Infrastructure\Queue;

final class QueueLock
{
    private function __construct() {}

    public static function key(string $kind, string $tenantId, string $entityId): string
    {
        $tuple = self::part($tenantId).self::part($entityId);

        return 'rick:'.$kind.':'.hash('sha256', $tuple);
    }

    private static function part(string $value): string
    {
        return strlen($value).':'.$value;
    }
}
