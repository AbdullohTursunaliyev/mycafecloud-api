<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\Pc;
use App\Models\PcCommand;
use App\Models\Session;
use App\Models\Tariff;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class AgentSessionController extends Controller
{
    public function start(Request $request)
    {
        // PC va tenant konteksti middleware'dan keladi
        $tenantId = (int) $request->attributes->get('tenant_id');
        $pcId     = (int) $request->attributes->get('pc_id');

        $data = $request->validate([
            'client_id' => ['required','integer'],
            'tariff_id' => ['required','integer'],
        ]);

        $pc = Pc::where('tenant_id', $tenantId)->findOrFail($pcId);

        // PC band emasligini tekshiramiz
        $busy = Session::where('tenant_id',$tenantId)
            ->where('pc_id',$pcId)
            ->where('status','active')
            ->exists();

        if ($busy) {
            throw ValidationException::withMessages([
                'pc' => 'ПК уже используется'
            ]);
        }

        $client = Client::where('tenant_id',$tenantId)
            ->findOrFail($data['client_id']);

        if ($client->status !== 'active') {
            throw ValidationException::withMessages([
                'client' => 'Клиент заблокирован'
            ]);
        }

        $tariff = Tariff::where('tenant_id',$tenantId)
            ->where('is_active',true)
            ->findOrFail($data['tariff_id']);

        // Zona mosligi
        if ($tariff->zone && $pc->zone && $tariff->zone !== $pc->zone) {
            throw ValidationException::withMessages([
                'tariff' => 'Тариф не подходит для этой зоны'
            ]);
        }

        // Kamida 1 minutlik balans
        $pricePerMin = (int) ceil($tariff->price_per_hour / 60);
        $wallet = (int)$client->balance + (int)$client->bonus;
        if ($wallet < $pricePerMin) {
            throw ValidationException::withMessages([
                'balance' => 'Недостаточно средств'
            ]);
        }

        // Session yaratamiz
        $session = Session::create([
            'tenant_id' => $tenantId,
            'pc_id' => $pcId,
            'operator_id' => null, // agent flow
            'client_id' => $client->id,
            'tariff_id' => $tariff->id,
            'started_at' => now(),
            'status' => 'active',
            'price_total' => 0,
        ]);

        // PC busy
        $pc->update(['status' => 'busy']);

        // UNLOCK command
        PcCommand::create([
            'tenant_id' => $tenantId,
            'pc_id' => $pcId,
            'type' => 'UNLOCK',
            'status' => 'pending',
        ]);

        return response()->json([
            'data' => [
                'session_id' => $session->id,
                'started_at' => $session->started_at->toIso8601String(),
            ]
        ], 201);
    }
}
