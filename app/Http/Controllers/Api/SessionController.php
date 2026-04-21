<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\Client;
use App\Models\ClientPackage;
use App\Models\Pc;
use App\Models\PcCommand;
use App\Models\Session;
use App\Models\Tariff;
use App\Models\Zone;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class SessionController extends Controller
{
    public function active(Request $request)
    {
        $tenantId = $request->user()->tenant_id;

        $sessions = Session::query()
            ->where('tenant_id', $tenantId)
            ->where('status', 'active')
            ->with(['pc.zoneRel','tariff','client','clientPackage'])
            ->orderByDesc('started_at')
            ->get()
            ->map(function (Session $s) {
                $pc = $s->pc;
                $client = $s->client;
                $tariff = $s->tariff;
                $time = $this->resolveSessionTime($s, $client, $pc, $tariff);

                return [
                    'id' => $s->id,
                    'pc' => $pc ? [
                        'id' => $pc->id,
                        'code' => $pc->code,
                        'zone' => $pc->zone,
                    ] : null,
                    'client' => $client ? [
                        'id' => $client->id,
                        'account_id' => $client->account_id,
                        'login' => $client->login,
                        'phone' => $client->phone,
                        'balance' => $client->balance,
                        'bonus' => $client->bonus,
                    ] : null,
                    'tariff' => $tariff ? [
                        'id' => $tariff->id,
                        'name' => $tariff->name,
                        'price_per_hour' => $tariff->price_per_hour,
                    ] : null,
                    'started_at' => $s->started_at?->toIso8601String(),
                    'price_total' => (int)$s->price_total,
                    'is_package' => (bool)$s->is_package,
                    'seconds_left' => $time['seconds_left'],
                    'from' => $time['from'],
                    'rate_per_hour' => $time['rate_per_hour'],
                ];
            });

        return response()->json(['data' => $sessions]);
    }

    private function resolveSessionTime(Session $session, ?Client $client, ?Pc $pc, ?Tariff $tariff): array
    {
        $anchor = $session->last_billed_at ?: $session->started_at;
        $elapsed = $anchor ? max(0, (int)$anchor->diffInSeconds(now())) : 0;

        if ((bool)$session->is_package && $session->client_package_id) {
            $cp = $session->relationLoaded('clientPackage') ? $session->clientPackage : null;
            if (!$cp) {
                $cp = ClientPackage::query()->whereKey($session->client_package_id)->first();
            }

            if ($cp && (int)$cp->remaining_min > 0) {
                $rawSeconds = max(0, (int)$cp->remaining_min) * 60;
                return [
                    'seconds_left' => max(0, $rawSeconds - $elapsed),
                    'from' => 'package',
                    'rate_per_hour' => 0,
                ];
            }
        }

        $ratePerHour = (int)($tariff?->price_per_hour ?? 0);
        if ($ratePerHour <= 0) {
            $ratePerHour = (int)($pc?->zoneRel?->price_per_hour ?? 0);
        }
        if ($ratePerHour <= 0 && $pc && !empty($pc->zone)) {
            $ratePerHour = (int) Zone::query()
                ->where('tenant_id', $pc->tenant_id)
                ->where('name', $pc->zone)
                ->value('price_per_hour');
        }

        if ($ratePerHour <= 0 || !$client) {
            return ['seconds_left' => 0, 'from' => 'balance', 'rate_per_hour' => $ratePerHour];
        }

        $wallet = max(0, (int)$client->balance) + max(0, (int)$client->bonus);
        $rawSeconds = (int) floor(($wallet / $ratePerHour) * 3600);

        return [
            'seconds_left' => max(0, $rawSeconds - $elapsed),
            'from' => 'balance',
            'rate_per_hour' => $ratePerHour,
        ];
    }

    public function start(Request $request)
    {
        $data = $request->validate([
            'pc_id' => ['required','integer'],
            'tariff_id' => ['required','integer'],
            'client_id' => ['required','integer'],
        ]);

        $tenantId = $request->user()->tenant_id;

        $pc = Pc::where('tenant_id', $tenantId)->findOrFail($data['pc_id']);



        // PC band emasligini tekshiramiz
        $already = Session::where('tenant_id', $tenantId)
            ->where('pc_id', $pc->id)
            ->where('status', 'active')
            ->exists();

        if ($already) {
            throw ValidationException::withMessages(['pc_id' => 'ПК уже занят']);
        }

        $tariff = Tariff::where('tenant_id', $tenantId)
            ->where('is_active', true)
            ->findOrFail($data['tariff_id']);

        $client = Client::where('tenant_id', $tenantId)->findOrFail($data['client_id']);

        $now = now();

        $currentBooking = Booking::where('tenant_id',$tenantId)
            ->where('pc_id',$pc->id)
            ->where('status','active')
            ->where('start_at','<=',$now)
            ->where('end_at','>=',$now)
            ->first();

        if ($currentBooking && $currentBooking->client_id !== $client->id) {
            throw ValidationException::withMessages([
                'booking' => 'ПК забронирован для другого клиента'
            ]);
        }

        if ($client->status !== 'active') {
            throw ValidationException::withMessages(['client_id' => 'Клиент заблокирован']);
        }
        if ($client->expires_at && $client->expires_at->isPast()) {
            throw ValidationException::withMessages(['client_id' => 'Аккаунт истёк']);
        }

        // ✅ Zona mosligi (tariff.zone bo'lsa)
        if ($tariff->zone && $pc->zone && $tariff->zone !== $pc->zone) {
            throw ValidationException::withMessages([
                'tariff_id' => 'Тариф не подходит для этой зоны'
            ]);
        }

        // ✅ Qarz yo‘q: kamida 1 minutga yetadigan balans bo‘lsin
        $pricePerMin = (int) ceil($tariff->price_per_hour / 60);
        $wallet = (int)$client->balance + (int)$client->bonus;
        if ($wallet < $pricePerMin) {
            throw ValidationException::withMessages([
                'balance' => 'Недостаточно средств'
            ]);
        }

        $session = Session::create([
            'tenant_id' => $tenantId,
            'pc_id' => $pc->id,
            'operator_id' => $request->user()->id,
            'client_id' => $client->id,
            'tariff_id' => $tariff->id,
            'started_at' => now(),
            'status' => 'active',
            'price_total' => 0,
        ]);

        // Booking bo'yicha kelgan client sessiyani boshlasa, booking yopiladi.
        if ($currentBooking && (int)$currentBooking->client_id === (int)$client->id) {
            $currentBooking->update(['status' => 'completed']);
        }

        // PC busy
        $pc->update(['status' => 'busy']);

        // PC unlock command
        PcCommand::create([
            'tenant_id' => $tenantId,
            'pc_id' => $pc->id,
            'type' => 'UNLOCK',
            'payload' => null,
            'status' => 'pending',
        ]);

        return response()->json([
            'data' => [
                'id' => $session->id,
                'pc_id' => $pc->id,
                'client_id' => $client->id,
                'started_at' => $session->started_at->toIso8601String(),
            ]
        ], 201);
    }

    public function stop(Request $request, int $id)
    {
        $tenantId = $request->user()->tenant_id;

        $session = \App\Models\Session::where('tenant_id', $tenantId)
            ->with(['pc','tariff','client'])
            ->findOrFail($id);

        if ($session->status !== 'active') {
            throw \Illuminate\Validation\ValidationException::withMessages(['id' => 'Сессия уже завершена']);
        }

        $endedAt = now();

        $session->update([
            'ended_at' => $endedAt,
            'status' => 'finished',
        ]);

        // PC lock (va agentga lock command)
        $session->pc->update(['status' => 'locked']);

        \App\Models\PcCommand::create([
            'tenant_id' => $tenantId,
            'pc_id' => $session->pc_id,
            'type' => 'LOCK',
            'status' => 'pending',
        ]);

        return response()->json([
            'data' => [
                'id' => $session->id,
                'ended_at' => $endedAt->toIso8601String(),
                'price_total' => $session->price_total, // billing tick davomida yig'ilgan
            ]
        ]);
    }
}
