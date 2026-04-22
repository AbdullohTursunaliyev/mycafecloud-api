<?php

namespace App\Services;

use App\Enums\PcCommandType;
use App\Models\Pc;
use App\Models\PcCommand;
use Illuminate\Validation\ValidationException;

class PcCommandService
{
    public function send(int $tenantId, int $pcId, string $type, ?array $payload, ?string $batchId): PcCommand
    {
        $pc = Pc::query()
            ->where('tenant_id', $tenantId)
            ->findOrFail($pcId);

        if ($pc->status === 'busy' && $type === PcCommandType::Shutdown->value) {
            throw ValidationException::withMessages([
                'type' => 'Нельзя выключить ПК во время сессии',
            ]);
        }

        return PcCommand::query()->create([
            'tenant_id' => $tenantId,
            'pc_id' => $pc->id,
            'batch_id' => $batchId,
            'type' => $type,
            'payload' => $payload,
            'status' => 'pending',
        ]);
    }
}
