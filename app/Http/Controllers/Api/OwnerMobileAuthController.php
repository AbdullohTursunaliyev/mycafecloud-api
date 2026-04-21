<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\LicenseKey;
use App\Models\Operator;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class OwnerMobileAuthController extends Controller
{
    public function login(Request $request)
    {
        $data = $request->validate([
            'license_key' => ['required','string'],
            'login'       => ['required','string'],
            'password'    => ['required','string'],
        ]);

        $license = LicenseKey::with('tenant')
            ->where('key', $data['license_key'])
            ->first();

        if (!$license || $license->status !== 'active') {
            throw ValidationException::withMessages(['license_key' => 'Лицензия недействительна']);
        }

        if ($license->expires_at && $license->expires_at->lte(Carbon::now())) {
            throw ValidationException::withMessages(['license_key' => 'Лицензия истекла']);
        }

        if ($license->tenant?->status !== 'active') {
            throw ValidationException::withMessages(['license_key' => 'Клуб заблокирован']);
        }

        $operator = Operator::where('tenant_id', $license->tenant_id)
            ->where('login', $data['login'])
            ->where('is_active', true)
            ->first();

        if (!$operator || !Hash::check($data['password'], $operator->password)) {
            throw ValidationException::withMessages(['login' => 'Неверный логин или пароль']);
        }

        if ($operator->role !== 'owner') {
            throw ValidationException::withMessages(['login' => 'Доступ только для владельца']);
        }

        // clear old tokens
        $operator->tokens()->delete();

        $token = $operator->createToken('owner-mobile', [
            'tenant:'.$operator->tenant_id,
            'role:'.$operator->role,
        ])->plainTextToken;

        $license->update(['last_used_at' => now()]);

        return response()->json([
            'token' => $token,
            'tenant' => [
                'id' => $license->tenant->id,
                'name' => $license->tenant->name,
            ],
            'operator' => [
                'id' => $operator->id,
                'name' => $operator->name,
                'role' => $operator->role,
            ],
        ]);
    }

    public function me(Request $request)
    {
        /** @var \App\Models\Operator $op */
        $op = $request->user();
        return response()->json([
            'operator' => [
                'id' => $op->id,
                'name' => $op->name,
                'login' => $op->login,
                'role' => $op->role,
                'tenant_id' => $op->tenant_id,
            ],
        ]);
    }

    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()?->delete();
        return response()->json(['ok' => true]);
    }
}
