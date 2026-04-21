<?php

namespace App\Http\Controllers\Cp;

use App\Http\Controllers\Controller;
use App\Models\LicenseKey;
use Carbon\Carbon;
use Illuminate\Http\Request;

class CpLicenseController extends Controller
{
    public function resolve(Request $request)
    {
        $data = $request->validate([
            'license_key' => ['required','string','max:100'],
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

        return response()->json([
            'tenant' => $license->tenant,
            'license' => [
                'id' => $license->id,
                'key' => $license->key,
                'status' => $license->status,
                'expires_at' => $license->expires_at,
            ],
        ]);
    }
}
