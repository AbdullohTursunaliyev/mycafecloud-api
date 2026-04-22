<?php
namespace App\Service;

class EventLogger
{
    public static function log(
        int $tenantId,
        string $type,
        string $source,
        string $entityType,
        int $entityId,
        array $payload = []
    ): void {
        app(\App\Services\EventLogger::class)->log(
            $tenantId,
            $type,
            $source,
            $entityType,
            $entityId,
            $payload,
        );
    }
}
