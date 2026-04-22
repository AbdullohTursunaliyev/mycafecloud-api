<?php

namespace App\Services;

use App\Models\Event;

class EventLogger
{
    public function log(
        int $tenantId,
        string $type,
        string $source,
        string $entityType,
        int $entityId,
        array $payload = []
    ): void {
        Event::query()->create([
            'tenant_id' => $tenantId,
            'type' => $type,
            'source' => $source,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'payload' => $payload ?: null,
        ]);
    }
}
