<?php

namespace App\Services;

use App\Models\LicenseKey;
use App\Models\Operator;
use Carbon\Carbon;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class OperatorAuthService
{
    public function loginApi(string $licenseKey, string $login, string $password): array
    {
        [$license, $operator] = $this->resolveApiCredentials($licenseKey, $login, $password);

        $operator->tokens()->delete();

        $token = $operator->createToken('operator-api', [
            'tenant:' . $operator->tenant_id,
            'role:' . $operator->role,
        ])->plainTextToken;

        $license->update(['last_used_at' => now()]);

        return [
            'token' => $token,
            'tenant' => [
                'id' => (int) $license->tenant->id,
                'name' => (string) $license->tenant->name,
            ],
            'operator' => [
                'id' => (int) $operator->id,
                'name' => (string) $operator->name,
                'role' => (string) $operator->role,
            ],
        ];
    }

    public function loginCp(string $licenseKey, string $login, string $password): array
    {
        [$license, $operator] = $this->resolveCpCredentials($licenseKey, $login, $password);

        $operator->tokens()->delete();
        $token = $operator->createToken('cp')->plainTextToken;

        return [
            'token' => $token,
            'tenant' => [
                'id' => (int) $license->tenant->id,
                'name' => (string) $license->tenant->name,
                'status' => (string) $license->tenant->status,
                'license_expires_at' => $license->expires_at?->toIso8601String(),
            ],
            'operator' => [
                'id' => (int) $operator->id,
                'login' => (string) $operator->login,
                'name' => (string) $operator->name,
                'role' => (string) $operator->role,
                'is_active' => (bool) $operator->is_active,
            ],
        ];
    }

    public function cpMe(Operator $operator): array
    {
        $license = LicenseKey::query()
            ->where('tenant_id', $operator->tenant_id)
            ->where('status', 'active')
            ->orderByDesc('expires_at')
            ->first();

        $tenant = $operator->tenant()->select('id', 'name', 'status')->first();

        return [
            'tenant' => $tenant ? [
                'id' => (int) $tenant->id,
                'name' => (string) $tenant->name,
                'status' => (string) $tenant->status,
                'license_expires_at' => $license?->expires_at?->toIso8601String(),
            ] : null,
            'operator' => [
                'id' => (int) $operator->id,
                'login' => (string) $operator->login,
                'name' => (string) $operator->name,
                'role' => (string) $operator->role,
                'is_active' => (bool) $operator->is_active,
            ],
        ];
    }

    private function resolveApiCredentials(string $licenseKey, string $login, string $password): array
    {
        $license = LicenseKey::query()
            ->with('tenant')
            ->where('key', $licenseKey)
            ->first();

        if (!$license || $license->status !== 'active') {
            throw ValidationException::withMessages([
                'license_key' => 'Лицензия недействительна',
            ]);
        }

        if ($license->expires_at && $license->expires_at->lte(Carbon::now())) {
            throw ValidationException::withMessages([
                'license_key' => 'Лицензия истекла',
            ]);
        }

        if ($license->tenant?->status !== 'active') {
            throw ValidationException::withMessages([
                'license_key' => 'Клуб заблокирован',
            ]);
        }

        $operator = Operator::query()
            ->where('tenant_id', $license->tenant_id)
            ->where('login', $login)
            ->where('is_active', true)
            ->first();

        if (!$operator || !Hash::check($password, (string) $operator->password)) {
            throw ValidationException::withMessages([
                'login' => 'Неверный логин или пароль',
            ]);
        }

        return [$license, $operator];
    }

    private function resolveCpCredentials(string $licenseKey, string $login, string $password): array
    {
        $license = LicenseKey::query()
            ->with(['tenant:id,name,status'])
            ->where('key', $licenseKey)
            ->first();

        if (!$license) {
            throw new HttpResponseException(response()->json([
                'message' => 'Лицензия не найдена',
            ], 404));
        }

        if ($license->status !== 'active') {
            throw new HttpResponseException(response()->json([
                'message' => 'Лицензия не активна',
            ], 403));
        }

        if ($license->expires_at && Carbon::parse($license->expires_at)->isPast()) {
            throw new HttpResponseException(response()->json([
                'message' => 'Срок лицензии истёк',
            ], 403));
        }

        if (!$license->tenant || $license->tenant->status !== 'active') {
            throw new HttpResponseException(response()->json([
                'message' => 'Клуб заблокирован',
            ], 403));
        }

        $operator = Operator::query()
            ->where('tenant_id', $license->tenant_id)
            ->where('login', $login)
            ->first();

        if (!$operator || !Hash::check($password, (string) $operator->password)) {
            throw new HttpResponseException(response()->json([
                'message' => 'Неверный логин или пароль',
            ], 401));
        }

        if (isset($operator->is_active) && !$operator->is_active) {
            throw new HttpResponseException(response()->json([
                'message' => 'Аккаунт отключён',
            ], 403));
        }

        return [$license, $operator];
    }
}
