<?php

namespace App\Http\Controllers\Saas;

use App\Http\Controllers\Controller;
use App\Models\LicenseKey;
use App\Models\Tenant;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class LicenseController extends Controller
{
    public function index(Request $request)
    {
        $q = LicenseKey::with('tenant')->orderByDesc('id');

        if ($request->filled('status')) $q->where('status', $request->string('status'));
        if ($request->filled('tenant_id')) $q->where('tenant_id', (int)$request->input('tenant_id'));

        return response()->json(['data' => $q->paginate(20)]);
    }

    public function createForTenant(Request $request, int $tenantId)
    {
        $tenant = Tenant::findOrFail($tenantId);

        $data = $request->validate([
            'expires_at' => ['nullable','date'],
        ]);

        $key = strtoupper(Str::random(4)).'-'.strtoupper(Str::random(4)).'-'.strtoupper(Str::random(4));

        $license = LicenseKey::create([
            'tenant_id' => $tenant->id,
            'key' => $key,
            'status' => 'active',
            'expires_at' => $data['expires_at'] ?? now()->addDays(30),
        ]);

        return response()->json(['data'=>$license], 201);
    }

    public function update(Request $request, int $id)
    {
        $license = LicenseKey::findOrFail($id);

        $data = $request->validate([
            'expires_at' => ['sometimes','nullable','date'],
            'status' => ['sometimes','in:active,revoked'],
        ]);

        $license->fill($data)->save();

        return response()->json(['data'=>$license]);
    }

    public function revoke(Request $request, int $id)
    {
        $license = LicenseKey::findOrFail($id);
        $license->update(['status'=>'revoked']);
        return response()->json(['ok'=>true]);
    }
}

