<?php

namespace App\Services;

use App\Enums\PcCommandType;
use App\Models\PcCommand;
use Carbon\Carbon;

class PcCommandDispatchService
{
    public function failStaleLockCommands(int $tenantId, int $pcId, ?Carbon $activeSessionStartedAt): void
    {
        if (!$activeSessionStartedAt) {
            return;
        }

        PcCommand::query()
            ->where('tenant_id', $tenantId)
            ->where('pc_id', $pcId)
            ->where('status', 'pending')
            ->where('type', PcCommandType::Lock->value)
            ->where('created_at', '<', $activeSessionStartedAt)
            ->update([
                'status' => 'failed',
                'ack_at' => now(),
                'error' => 'stale_lock_ignored_active_session',
            ]);
    }

    public function deliverNextPendingCommand(
        int $tenantId,
        int $pcId,
        ?Carbon $activeSessionStartedAt = null,
        ?array $allowedTypes = null,
    ): ?array {
        $allowedTypes ??= PcCommandType::values();

        $this->failStaleLockCommands($tenantId, $pcId, $activeSessionStartedAt);

        $query = PcCommand::query()
            ->where('tenant_id', $tenantId)
            ->where('pc_id', $pcId)
            ->where('status', 'pending')
            ->whereIn('type', $allowedTypes)
            ->orderBy('id');

        if ($activeSessionStartedAt) {
            $query->where(function ($builder) use ($activeSessionStartedAt) {
                $builder->where('type', '!=', PcCommandType::Lock->value)
                    ->orWhere('created_at', '>=', $activeSessionStartedAt);
            });
        }

        $command = $query->first();
        if (!$command) {
            return null;
        }

        $command->status = 'sent';
        $command->sent_at = now();
        $command->save();

        return [
            'id' => (int) $command->id,
            'type' => (string) $command->type,
            'payload' => $command->payload,
        ];
    }

    public function acknowledge(
        int $tenantId,
        int $pcId,
        int $commandId,
        string $status,
        ?string $error = null,
    ): ?PcCommand {
        $command = PcCommand::query()
            ->where('tenant_id', $tenantId)
            ->where('pc_id', $pcId)
            ->where('id', $commandId)
            ->first();

        if (!$command) {
            return null;
        }

        if (!in_array((string) $command->status, ['done', 'failed'], true)) {
            $command->status = $status;
            $command->ack_at = now();
            $command->error = $status === 'failed'
                ? ((string) ($error ?: 'Agent execution failed'))
                : null;
            $command->save();
        }

        return $command;
    }
}
