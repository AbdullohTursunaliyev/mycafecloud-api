<?php

namespace App\Services;

use App\Models\PcPairCode;
use Illuminate\Support\Str;

class PcPairCodeService
{
    public function create(int $tenantId, ?string $zone, int $expiresInMinutes): PcPairCode
    {
        return PcPairCode::query()->create([
            'tenant_id' => $tenantId,
            'code' => $this->generateCode(),
            'zone' => $zone !== null && $zone !== '' ? $zone : null,
            'expires_at' => now()->addMinutes($expiresInMinutes),
        ]);
    }

    private function generateCode(): string
    {
        do {
            $code = Str::upper(Str::random(4)) . '-' . Str::upper(Str::random(2));
        } while (PcPairCode::query()->where('code', $code)->exists());

        return $code;
    }
}
