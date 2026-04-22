<?php

namespace App\Services;

use App\Models\Tenant;
use Illuminate\Support\Str;

class TenantJoinCodeService
{
    public function refresh(Tenant $tenant): array
    {
        $tenant->join_code = $this->generateJoinCode();
        $tenant->join_code_active = true;
        $tenant->join_code_expires_at = now()->addDays((int) config('domain.tenant.join_code.ttl_days', 30));
        $tenant->save();

        return [
            'join_code' => (string) $tenant->join_code,
            'expires_at' => optional($tenant->join_code_expires_at)->toIso8601String(),
            'active' => (bool) $tenant->join_code_active,
        ];
    }

    private function generateJoinCode(): string
    {
        $length = max(6, min(32, (int) config('domain.tenant.join_code.length', 10)));

        do {
            $code = Str::upper(Str::random($length));
        } while (Tenant::query()->where('join_code', $code)->exists());

        return $code;
    }
}
