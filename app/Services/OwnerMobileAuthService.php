<?php

namespace App\Services;

use App\Models\LicenseKey;
use App\Models\Operator;
use Carbon\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class OwnerMobileAuthService
{
    public function login(string $licenseKey, string $login, string $password): array
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

        if ($operator->role !== 'owner') {
            throw ValidationException::withMessages([
                'login' => 'Доступ только для владельца',
            ]);
        }

        $operator->tokens()->delete();

        $token = $operator->createToken('owner-mobile', [
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
}
