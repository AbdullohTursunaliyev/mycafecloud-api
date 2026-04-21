<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PcPairCode;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class PcPairCodeController extends Controller
{
    public function create(Request $request)
    {
        $tenantId = $request->user()->tenant_id;

        $data = $request->validate([
            'zone' => ['nullable','string','max:32'],
            'expires_in_min' => ['nullable','integer','min:1','max:120'],
        ]);

        $code = Str::upper(Str::random(4)).'-'.Str::upper(Str::random(2));

        $pair = PcPairCode::create([
            'tenant_id' => $tenantId,
            'code' => $code,
            'zone' => $data['zone'] ?? null,
            'expires_at' => now()->addMinutes($data['expires_in_min'] ?? 10),
        ]);

        return response()->json([
            'data' => [
                'code' => $pair->code,
                'expires_at' => $pair->expires_at->toIso8601String(),
            ]
        ], 201);
    }
}

