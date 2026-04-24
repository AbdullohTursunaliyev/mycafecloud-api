<?php

namespace App\Services;

use App\Models\Session;
use App\Models\SessionChargeEvent;

class SessionChargeEventService
{
    public function record(Session $session, array $payload): SessionChargeEvent
    {
        return SessionChargeEvent::query()->create([
            'tenant_id' => (int) $session->tenant_id,
            'session_id' => (int) $session->id,
            'client_id' => $session->client_id ? (int) $session->client_id : null,
            'pc_id' => $session->pc_id ? (int) $session->pc_id : null,
            'zone_id' => $payload['zone_id'] ?? null,
            'source_type' => (string) ($payload['source_type'] ?? 'wallet'),
            'rule_type' => $payload['rule_type'] ?? null,
            'rule_id' => isset($payload['rule_id']) ? (int) $payload['rule_id'] : null,
            'period_started_at' => $payload['period_started_at'],
            'period_ended_at' => $payload['period_ended_at'],
            'billable_units' => max(0, (int) ($payload['billable_units'] ?? 0)),
            'unit_kind' => (string) ($payload['unit_kind'] ?? 'minute'),
            'unit_price' => max(0, (int) ($payload['unit_price'] ?? 0)),
            'amount' => max(0, (int) ($payload['amount'] ?? 0)),
            'wallet_before' => isset($payload['wallet_before']) ? max(0, (int) $payload['wallet_before']) : null,
            'wallet_after' => isset($payload['wallet_after']) ? max(0, (int) $payload['wallet_after']) : null,
            'package_before_min' => isset($payload['package_before_min']) ? max(0, (int) $payload['package_before_min']) : null,
            'package_after_min' => isset($payload['package_after_min']) ? max(0, (int) $payload['package_after_min']) : null,
            'meta' => $payload['meta'] ?? null,
        ]);
    }
}
