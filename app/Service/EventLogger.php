<?php
namespace App\Service;

use App\Models\Event;

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
        Event::create([
            'tenant_id' => $tenantId,
            'type' => $type,
            'source' => $source,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'payload' => $payload ?: null,
        ]);
    }
}
