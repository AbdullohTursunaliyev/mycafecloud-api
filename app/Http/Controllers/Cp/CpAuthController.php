<?php

namespace App\Http\Controllers\Cp;

use App\Http\Controllers\Controller;
use App\Models\LicenseKey;
use App\Models\Operator;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class CpAuthController extends Controller
{
    public function login(Request $request)
    {
        $data = $request->validate([
            'license_key' => ['required','string','max:100'],
            'login'       => ['required','string','max:50'],
            'password'    => ['required','string'],
        ]);

        $license = LicenseKey::query()
            ->with(['tenant:id,name,status'])
            ->where('key', $data['license_key'])
            ->first();

        if (!$license) {
            return response()->json(['message' => 'Лицензия не найдена'], 404);
        }

        if ($license->status !== 'active') {
            return response()->json(['message' => 'Лицензия не активна'], 403);
        }

        if ($license->expires_at && Carbon::parse($license->expires_at)->isPast()) {
            return response()->json(['message' => 'Срок лицензии истёк'], 403);
        }

        if (!$license->tenant || $license->tenant->status !== 'active') {
            return response()->json(['message' => 'Клуб заблокирован'], 403);
        }

        $operator = Operator::query()
            ->where('tenant_id', $license->tenant_id)
            ->where('login', $data['login'])
            ->first();

        if (!$operator) {
            return response()->json(['message' => 'Неверный логин или пароль'], 401);
        }

        if (isset($operator->is_active) && !$operator->is_active) {
            return response()->json(['message' => 'Аккаунт отключён'], 403);
        }

        if (!Hash::check($data['password'], $operator->password)) {
            return response()->json(['message' => 'Неверный логин или пароль'], 401);
        }

        $token = $operator->createToken('cp')->plainTextToken;

        return response()->json([
            'token' => $token,
            'tenant' => [
                'id' => $license->tenant->id,
                'name' => $license->tenant->name,
                'status' => $license->tenant->status,
                'license_expires_at' => $license->expires_at,
            ],
            'operator' => [
                'id' => $operator->id,
                'login' => $operator->login,
                'name' => $operator->name,
                'role' => $operator->role,
                'is_active' => $operator->is_active,
            ],
        ]);
    }

    public function me(Request $request)
    {
        /** @var \App\Models\Operator $operator */
        $operator = $request->user('operator');
        $license = LicenseKey::query()
            ->where('tenant_id', $operator->tenant_id)
            ->where('status', 'active')
            ->orderByDesc('expires_at')
            ->first();

        return response()->json([
            'tenant' => $operator->tenant()->select('id','name','status')->first()?->setAttribute('license_expires_at', $license?->expires_at),
            'operator' => [
                'id' => $operator->id,
                'login' => $operator->login,
                'name' => $operator->name,
                'role' => $operator->role,
                'is_active' => $operator->is_active,
            ],
        ]);
    }

    public function logout(Request $request)
    {
        /** @var \App\Models\Operator $operator */
        $operator = $request->user('operator');

        $operator->currentAccessToken()?->delete();

        return response()->json(['ok' => true]);
    }
}
