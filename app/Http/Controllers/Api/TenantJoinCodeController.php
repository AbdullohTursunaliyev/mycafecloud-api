<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class TenantJoinCodeController extends Controller
{
    public function refresh(Request $request)
    {
        $operator = $request->user(); // auth:operator
        $tenant = $operator->tenant;  // relationship bo‘lsa

        // agar relationship bo'lmasa:
        // $tenant = \App\Models\Tenant::findOrFail($operator->tenant_id);

        $tenant->join_code = Str::upper(Str::random(10)); // masalan: 10 ta belgidan
        $tenant->join_code_active = true;
        $tenant->join_code_expires_at = now()->addDays(30); // xohlasang null qoldir
        $tenant->save();

        return response()->json([
            'data' => [
                'join_code' => $tenant->join_code,
                'expires_at' => $tenant->join_code_expires_at,
                'active' => (bool)$tenant->join_code_active,
            ]
        ]);
    }
}

