<?php

namespace App\Services;

use App\Models\ClientToken;
use Carbon\Carbon;
use Illuminate\Support\Str;

class ClientTokenService
{
    public function issue(int $tenantId, int $clientId, ?Carbon $expiresAt = null): array
    {
        $plain = Str::random(48);

        $token = ClientToken::create([
            'tenant_id' => $tenantId,
            'client_id' => $clientId,
            'token_hash' => hash('sha256', $plain),
            'expires_at' => $expiresAt ?? now()->addHours((int) config('domain.auth.client_token_ttl_hours', 12)),
            'last_used_at' => now(),
        ]);

        return [
            'plain' => $plain,
            'token' => $token,
        ];
    }

    public function resolve(?string $plain, ?int $tenantId = null, bool $touch = false): ?ClientToken
    {
        $plain = is_string($plain) ? trim($plain) : '';
        if ($plain === '') {
            return null;
        }

        $query = ClientToken::query()
            ->where('token_hash', hash('sha256', $plain))
            ->where(function ($q) {
                $q->whereNull('expires_at')
                    ->orWhere('expires_at', '>', now());
            });

        if ($tenantId !== null) {
            $query->where('tenant_id', $tenantId);
        }

        $token = $query->first();
        if ($token && $touch) {
            $token->update(['last_used_at' => now()]);
        }

        return $token;
    }

    public function revoke(ClientToken $token): void
    {
        $token->delete();
    }
}
