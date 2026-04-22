<?php

namespace App\Services;

use App\Models\Client;
use App\Models\ClientToken;
use App\Models\LicenseKey;
use App\Models\Pc;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class ClientShellContextService
{
    public function __construct(
        private readonly ClientTokenService $tokens,
    ) {
    }

    public function resolveLicenseForLogin(string $licenseKey): LicenseKey
    {
        $license = LicenseKey::with('tenant')
            ->where('key', $licenseKey)
            ->first();

        if (!$license || $license->status !== 'active' || ($license->expires_at && $license->expires_at->isPast())) {
            throw ValidationException::withMessages([
                'license_key' => 'License is invalid',
            ]);
        }

        if ($license->tenant?->status !== 'active') {
            throw ValidationException::withMessages([
                'license_key' => 'Tenant is blocked',
            ]);
        }

        return $license;
    }

    public function resolveLicenseForShell(string $licenseKey): LicenseKey
    {
        $license = LicenseKey::with('tenant')
            ->where('key', $licenseKey)
            ->first();

        if (!$license || $license->status !== 'active' || ($license->expires_at && $license->expires_at->isPast())) {
            throw new HttpResponseException(response()->json(['message' => 'License invalid'], 403));
        }

        if ($license->tenant?->status !== 'active') {
            throw new HttpResponseException(response()->json(['message' => 'Tenant blocked'], 403));
        }

        return $license;
    }

    public function resolvePcForLogin(int $tenantId, string $pcCode, bool $withZone = true): Pc
    {
        $pc = $this->findPc($tenantId, $pcCode, $withZone);

        if (!$pc) {
            throw ValidationException::withMessages([
                'pc_code' => 'PC not found',
            ]);
        }

        return $pc;
    }

    public function resolvePcForShell(int $tenantId, string $pcCode, bool $withZone = true): Pc
    {
        $pc = $this->findPc($tenantId, $pcCode, $withZone);

        if (!$pc) {
            throw new HttpResponseException(response()->json(['message' => 'PC not found'], 404));
        }

        return $pc;
    }

    public function findPc(int $tenantId, string $pcCode, bool $withZone = true): ?Pc
    {
        $query = Pc::query()->where('tenant_id', $tenantId)->where('code', $pcCode);

        if ($withZone) {
            $query->with('zoneRel');
        }

        return $query->first();
    }

    public function resolveActiveClientByCredentials(
        int $tenantId,
        ?string $accountId,
        ?string $login,
        ?string $password,
    ): Client {
        if ($accountId !== null && trim($accountId) !== '') {
            $client = Client::query()
                ->where('tenant_id', $tenantId)
                ->where('account_id', trim($accountId))
                ->first();

            if (!$client) {
                throw ValidationException::withMessages([
                    'account_id' => 'Account not found',
                ]);
            }

            return $this->ensureClientCanLogin($client);
        }

        $identifier = trim((string) $login);
        if ($identifier === '' || trim((string) $password) === '') {
            throw ValidationException::withMessages([
                'login' => 'Login and password are required',
            ]);
        }

        $client = Client::query()
            ->where('tenant_id', $tenantId)
            ->where(function ($query) use ($identifier) {
                $query->where('login', $identifier)
                    ->orWhere('account_id', $identifier)
                    ->orWhere('phone', $identifier);
            })
            ->orderByRaw(
                "CASE
                    WHEN login = ? THEN 0
                    WHEN account_id = ? THEN 1
                    WHEN phone = ? THEN 2
                    ELSE 3
                END",
                [$identifier, $identifier, $identifier],
            )
            ->first();

        if (!$client || !$client->password || !Hash::check((string) $password, $client->password)) {
            throw ValidationException::withMessages([
                'login' => 'Invalid login or password',
            ]);
        }

        return $this->ensureClientCanLogin($client);
    }

    /**
     * @return array{token: ClientToken, client: Client}
     */
    public function resolveShellClient(?string $bearer, int $tenantId, bool $touch = true): array
    {
        $plain = trim((string) $bearer);
        if ($plain === '') {
            throw new HttpResponseException(response()->json(['message' => 'No token'], 401));
        }

        $token = $this->tokens->resolve($plain, $tenantId, $touch);
        if (!$token) {
            throw new HttpResponseException(response()->json(['message' => 'Token invalid'], 401));
        }

        $client = Client::query()
            ->where('tenant_id', $tenantId)
            ->find($token->client_id);

        if (!$client) {
            throw new HttpResponseException(response()->json(['message' => 'Client not found'], 404));
        }

        if ($client->status !== 'active') {
            throw new HttpResponseException(response()->json(['message' => 'Client blocked'], 403));
        }

        if ($client->expires_at && $client->expires_at->isPast()) {
            throw new HttpResponseException(response()->json(['message' => 'Client expired'], 403));
        }

        return [
            'token' => $token,
            'client' => $client,
        ];
    }

    private function ensureClientCanLogin(Client $client): Client
    {
        if ($client->status !== 'active') {
            throw ValidationException::withMessages([
                'status' => 'Account blocked',
            ]);
        }

        if ($client->expires_at && $client->expires_at->isPast()) {
            throw ValidationException::withMessages([
                'expires_at' => 'Account expired',
            ]);
        }

        return $client;
    }
}
