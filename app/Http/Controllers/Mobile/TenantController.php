<?php

namespace App\Http\Controllers\Mobile;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\ClientMembership;
use App\Models\Tenant;
use App\Models\TenantInvite;
use Illuminate\Http\Request;

class TenantController extends Controller
{
    public function joinByCode(Request $request)
    {
        $identity = $request->attributes->get('identity');

        $data = $request->validate([
            'code' => ['required','string']
        ]);

        $tenant = Tenant::query()
            ->where('join_code', $data['code'])
            ->where('join_code_active', true)
            ->where(function ($q) {
                $q->whereNull('join_code_expires_at')
                    ->orWhere('join_code_expires_at', '>', now());
            })
            ->first();

        if (!$tenant) {
            return response()->json(['message' => 'Код клуба недействителен'], 422);
        }

        $tenantId = $tenant->id;

        // membership mavjudmi
        $exists = ClientMembership::where('identity_id',$identity->id)
            ->where('tenant_id',$tenantId)
            ->exists();

        if ($exists) {
            return response()->json(['message'=>'Already joined'],422);
        }

        // clients jadvaliga yozamiz
        $client = Client::create([
            'tenant_id' => $tenantId,
            'login' => $identity->login,
            'balance' => 0,
            'bonus' => 0,
            'status' => 'active',
        ]);

        ClientMembership::create([
            'identity_id' => $identity->id,
            'tenant_id' => $tenantId,
            'client_id' => $client->id
        ]);

        return response()->json([
            'message'=>'Joined successfully'
        ]);
    }
}

