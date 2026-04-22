<?php

namespace App\Services;

use App\Models\PcDeviceToken;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class PcDeviceTokenService
{
    public function issue(int $tenantId, int $pcId, ?Carbon $expiresAt = null, ?int $rotatedFromId = null): array
    {
        $plain = Str::random(48);

        $token = PcDeviceToken::query()->create([
            'tenant_id' => $tenantId,
            'pc_id' => $pcId,
            'token_hash' => hash('sha256', $plain),
            'expires_at' => $expiresAt ?? now()->addHours((int) config('domain.agent.device_token_ttl_hours', 24 * 30)),
            'last_used_at' => now(),
            'rotated_from_id' => $rotatedFromId,
        ]);

        return [
            'plain' => $plain,
            'token' => $token,
        ];
    }

    public function resolve(?string $plain, bool $touch = false): ?PcDeviceToken
    {
        $plain = is_string($plain) ? trim($plain) : '';
        if ($plain === '') {
            return null;
        }

        $token = PcDeviceToken::query()
            ->where('token_hash', hash('sha256', $plain))
            ->whereNull('revoked_at')
            ->where(function ($query) {
                $query->whereNull('expires_at')
                    ->orWhere('expires_at', '>', now());
            })
            ->first();

        if ($token && $touch) {
            $token->forceFill(['last_used_at' => now()])->save();
        }

        return $token;
    }

    public function revoke(PcDeviceToken $token, ?string $reason = null): void
    {
        if ($token->revoked_at) {
            return;
        }

        $token->forceFill([
            'revoked_at' => now(),
            'revocation_reason' => $reason,
        ])->save();
    }

    public function revokeActiveForPc(int $tenantId, int $pcId, ?string $reason = null, ?int $exceptId = null): void
    {
        PcDeviceToken::query()
            ->where('tenant_id', $tenantId)
            ->where('pc_id', $pcId)
            ->when($exceptId !== null, fn($query) => $query->where('id', '!=', $exceptId))
            ->whereNull('revoked_at')
            ->update([
                'revoked_at' => now(),
                'revocation_reason' => $reason,
                'updated_at' => now(),
            ]);
    }

    public function rotate(PcDeviceToken $token): array
    {
        return DB::transaction(function () use ($token) {
            $locked = PcDeviceToken::query()
                ->lockForUpdate()
                ->findOrFail($token->id);

            $this->revoke($locked, 'rotated');

            return $this->issue(
                (int) $locked->tenant_id,
                (int) $locked->pc_id,
                now()->addHours((int) config('domain.agent.device_token_ttl_hours', 24 * 30)),
                (int) $locked->id,
            );
        });
    }

    public function shouldRotate(PcDeviceToken $token): bool
    {
        if (!$token->expires_at) {
            return false;
        }

        return $token->expires_at->lte(
            now()->addHours((int) config('domain.agent.device_token_rotate_before_hours', 24))
        );
    }
}
