<?php

namespace App\Services;

use App\Enums\PcCommandType;
use App\Enums\PcStatus;
use App\Models\PcHeartbeat;
use App\Models\Session;

class PcHeartbeatService
{
    public function __construct(
        private readonly ClientShellContextService $context,
        private readonly PcCommandDispatchService $commands,
    ) {
    }

    public function heartbeat(array $attributes): array
    {
        $license = $this->context->resolveLicenseForShell((string) $attributes['license_key']);
        $tenantId = (int) $license->tenant_id;
        $pc = $this->context->resolvePcForShell($tenantId, (string) $attributes['pc_code'], false);

        $pc->last_seen_at = now();

        if ($pc->status === PcStatus::Offline->value) {
            $pc->status = PcStatus::Online->value;
        }

        if (!empty($attributes['metrics']['ip_address'])) {
            $pc->ip_address = (string) $attributes['metrics']['ip_address'];
        }

        $pc->save();

        if (!empty($attributes['metrics']) && is_array($attributes['metrics'])) {
            PcHeartbeat::query()->create([
                'tenant_id' => $tenantId,
                'pc_id' => $pc->id,
                'received_at' => now(),
                'metrics' => $attributes['metrics'],
            ]);
        }

        $activeSessionStartedAt = Session::query()
            ->where('tenant_id', $tenantId)
            ->where('pc_id', $pc->id)
            ->whereNull('ended_at')
            ->latest('id')
            ->first(['started_at'])
            ?->started_at;

        return [
            'ok' => true,
            'command' => $this->commands->deliverNextPendingCommand(
                $tenantId,
                (int) $pc->id,
                $activeSessionStartedAt,
                PcCommandType::agentDeliveryValues(),
            ),
        ];
    }

    public function acknowledge(array $attributes): array
    {
        $license = $this->context->resolveLicenseForShell((string) $attributes['license_key']);
        $tenantId = (int) $license->tenant_id;
        $pc = $this->context->resolvePcForShell($tenantId, (string) $attributes['pc_code'], false);

        $command = $this->commands->acknowledge(
            $tenantId,
            (int) $pc->id,
            (int) $attributes['command_id'],
            (string) $attributes['status'],
            $attributes['error'] ?? null,
        );

        if (!$command) {
            return [
                'message' => 'Command not found',
                'status_code' => 404,
            ];
        }

        return [
            'ok' => true,
            'data' => [
                'id' => (int) $command->id,
                'status' => (string) $command->status,
            ],
        ];
    }
}
