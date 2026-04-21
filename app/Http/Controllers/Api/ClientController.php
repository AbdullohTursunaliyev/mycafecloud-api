<?php

// app/Http/Controllers/Api/ClientController.php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\ClientTransaction;
use App\Models\Shift;
use App\Services\ClientTierService;
use App\Services\PromotionEngine;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ClientController extends Controller
{
    public function index(Request $request)
    {
        $tenantId = $request->user()->tenant_id;

        $q = Client::where('tenant_id', $tenantId)->orderByDesc('id');

        if ($request->filled('status')) {
            $q->where('status', $request->string('status'));
        }

        if ($request->filled('search')) {
            $s = $request->string('search');
            $hasPhone = Schema::hasColumn('clients', 'phone');
            $hasUsername = Schema::hasColumn('clients', 'username');
            $hasName = Schema::hasColumn('clients', 'name');

            $q->where(function ($qq) use ($s, $hasPhone, $hasUsername, $hasName) {
                $qq->where('account_id', 'ILIKE', "%{$s}%")
                    ->orWhere('login', 'ILIKE', "%{$s}%");
                if ($hasPhone) {
                    $qq->orWhere('phone', 'ILIKE', "%{$s}%");
                }
                if ($hasUsername) {
                    $qq->orWhere('username', 'ILIKE', "%{$s}%");
                }
                if ($hasName) {
                    $qq->orWhere('name', 'ILIKE', "%{$s}%");
                }
            });
        }

        return response()->json(['data' => $q->paginate(20)]);
    }

    public function store(Request $request)
    {
        $tenantId = $request->user()->tenant_id;

        $data = $request->validate([
            'account_id' => [
                'nullable','string','max:64',
                Rule::unique('clients')->where(fn($q)=>$q->where('tenant_id',$tenantId))
            ],
            'login' => [
                'nullable','string','max:64',
                Rule::unique('clients')->where(fn($q)=>$q->where('tenant_id',$tenantId))
            ],
            'password' => ['nullable','string','min:4'],
            'phone' => ['nullable','string','max:32'],
            'username' => ['nullable','string','max:64'],
            'name' => ['nullable','string','max:64'],
        ]);

        if (empty($data['account_id']) && empty($data['login'])) {
            throw ValidationException::withMessages([
                'account_id' => 'Укажите account_id или login',
            ]);
        }

        $name = $data['name'] ?? null;
        $username = $data['username'] ?? $name ?? null;
        $phone = $data['phone'] ?? null;

        $payload = [
            'tenant_id' => $tenantId,
            'account_id' => $data['account_id'] ?? null,
            'login' => $data['login'] ?? null,
            'password' => isset($data['password']) ? Hash::make($data['password']) : null,
            'balance' => 0,
            'bonus' => 0,
            'status' => 'active',
        ];
        if ($phone && Schema::hasColumn('clients', 'phone')) {
            $payload['phone'] = $phone;
        }
        if ($name && Schema::hasColumn('clients', 'name')) {
            $payload['name'] = $name;
        }
        if ($username) {
            if (Schema::hasColumn('clients', 'username')) {
                $payload['username'] = $username;
            } elseif (Schema::hasColumn('clients', 'name')) {
                $payload['name'] = $username;
            }
        }

        $client = Client::create($payload);

        return response()->json(['data' => $client], 201);
    }

    public function topup(Request $request, int $id)
    {
        $tenantId = $request->user()->tenant_id;
        $operatorId = $request->user()->id;

        $data = $request->validate([
            'amount' => ['required','integer','min:1'],
            'payment_method' => ['required','string','in:cash,card,balance'],
            'bonus_amount' => ['nullable','integer','min:0'],
            'comment' => ['nullable','string','max:255'],
        ]);

        $client = Client::where('tenant_id', $tenantId)->findOrFail($id);

        $amount = (int)$data['amount'];
        $paymentMethod = (string)$data['payment_method'];
        $manualBonus = (int)($data['bonus_amount'] ?? 0);

        DB::transaction(function () use ($client, $tenantId, $operatorId, $data, $amount, $paymentMethod, $manualBonus) {

            $shift = Shift::query()
                ->where('tenant_id', $tenantId)
                ->whereNull('closed_at')
                ->latest('id')
                ->first();

            if (!$shift) {
                throw ValidationException::withMessages(['shift' => 'Смена не открыта']);
            }

            // PROMO bonus (2x) (sizda bor)
            $promoRes = PromotionEngine::calcTopupBonus($tenantId, $paymentMethod, $amount, now());
            $promo = $promoRes['promotion'];
            $promoBonus = (int)$promoRes['bonus'];

            $finalBonus = $promoBonus + $manualBonus;

            // 1) balance +amount (FAqat 1 marta!)
            $client->increment('balance', $amount);

            // 2) bonus (promo + manual)
            if ($finalBonus > 0) {
                $client->increment('bonus', $finalBonus);
            }

            // 3) lifetime topup ham yangilanadi (tier uchun)
            $client->increment('lifetime_topup', $amount);

            // 4) transaction
            ClientTransaction::create([
                'tenant_id' => $tenantId,
                'client_id' => $client->id,
                'operator_id' => $operatorId,
                'shift_id' => $shift->id,
                'type' => 'topup',
                'amount' => $amount,
                'bonus_amount' => $finalBonus,
                'payment_method' => $paymentMethod,
                'comment' => $data['comment'] ?? null,
                'promotion_id' => $promo?->id,
            ]);

            // 5) tier recalc + upgrade bonus
            ClientTierService::recalcAndApplyUpgradeBonus(
                $client->fresh(),   // yangilangan qiymatlarni olib
                $tenantId,
                $operatorId,
                $shift->id,
                now()
            );
        });

        $client->refresh();

        return response()->json([
            'data' => [
                'balance' => $client->balance,
                'bonus' => $client->bonus,
                'tier_id' => $client->tier_id,
                'lifetime_topup' => $client->lifetime_topup,
            ]
        ]);
    }

    public function bulkTopup(Request $request)
    {
        $tenantId = $request->user()->tenant_id;
        $operatorId = $request->user()->id;

        $data = $request->validate([
            'client_ids' => ['required','array','min:1','max:200'],
            'client_ids.*' => ['integer'],
            'amount' => ['required','integer','min:1'],
            'payment_method' => ['required','string','in:cash,card,balance'],
            'bonus_amount' => ['nullable','integer','min:0'],
            'comment' => ['nullable','string','max:255'],
        ]);

        $clientIds = array_values(array_unique($data['client_ids']));
        if (count($clientIds) === 0) {
            throw ValidationException::withMessages(['client_ids' => 'Clients list is empty']);
        }

        $amount = (int)$data['amount'];
        $paymentMethod = (string)$data['payment_method'];
        $manualBonus = (int)($data['bonus_amount'] ?? 0);

        $shift = Shift::query()
            ->where('tenant_id', $tenantId)
            ->whereNull('closed_at')
            ->latest('id')
            ->first();

        if (!$shift) {
            throw ValidationException::withMessages(['shift' => 'РЎРјРµРЅР° РЅРµ РѕС‚РєСЂС‹С‚Р°']);
        }

        $results = [];

        DB::transaction(function () use (
            $tenantId,
            $operatorId,
            $clientIds,
            $amount,
            $paymentMethod,
            $manualBonus,
            $data,
            $shift,
            &$results
        ) {
            $now = now();

            foreach ($clientIds as $clientId) {
                $client = Client::where('tenant_id', $tenantId)
                    ->where('id', $clientId)
                    ->lockForUpdate()
                    ->first();

                if (!$client) {
                    throw ValidationException::withMessages([
                        'client_ids' => "Client not found: {$clientId}",
                    ]);
                }

                $promoRes = PromotionEngine::calcTopupBonus($tenantId, $paymentMethod, $amount, $now);
                $promo = $promoRes['promotion'];
                $promoBonus = (int)$promoRes['bonus'];

                $finalBonus = $promoBonus + $manualBonus;

                $client->increment('balance', $amount);
                if ($finalBonus > 0) {
                    $client->increment('bonus', $finalBonus);
                }
                $client->increment('lifetime_topup', $amount);

                ClientTransaction::create([
                    'tenant_id' => $tenantId,
                    'client_id' => $client->id,
                    'operator_id' => $operatorId,
                    'shift_id' => $shift->id,
                    'type' => 'topup',
                    'amount' => $amount,
                    'bonus_amount' => $finalBonus,
                    'payment_method' => $paymentMethod,
                    'comment' => $data['comment'] ?? null,
                    'promotion_id' => $promo?->id,
                ]);

                ClientTierService::recalcAndApplyUpgradeBonus(
                    $client->fresh(),
                    $tenantId,
                    $operatorId,
                    $shift->id,
                    $now
                );

                $client->refresh();
                $results[] = [
                    'id' => $client->id,
                    'balance' => $client->balance,
                    'bonus' => $client->bonus,
                    'tier_id' => $client->tier_id,
                    'lifetime_topup' => $client->lifetime_topup,
                ];
            }
        });

        return response()->json([
            'data' => [
                'count' => count($results),
                'items' => $results,
            ],
        ]);
    }


    public function history(Request $request, int $id)
    {
        $tenantId = $request->user()->tenant_id;

        $client = Client::where('tenant_id', $tenantId)->findOrFail($id);

        $tx = ClientTransaction::where('tenant_id', $tenantId)
            ->where('client_id', $client->id)
            ->orderByDesc('id');

        $date = $request->query('date');
        if ($date) {
            try {
                $d = Carbon::parse($date)->toDateString();
                $tx->whereDate('created_at', $d);
            } catch (\Throwable $e) {
                // ignore invalid date
            }
        }

        $tx = $tx->paginate(20);

        return response()->json(['data' => $tx]);
    }

    public function sessions(Request $request, int $id)
    {
        $tenantId = $request->user()->tenant_id;

        $client = Client::where('tenant_id', $tenantId)->findOrFail($id);

        $q = \App\Models\Session::query()
            ->where('tenant_id', $tenantId)
            ->where('client_id', $client->id)
            ->with([
                'pc:id,code,zone',
                'tariff:id,name,price_per_hour',
            ])
            ->orderByDesc('id');

        $date = $request->query('date');
        if ($date) {
            try {
                $d = Carbon::parse($date)->toDateString();
                $q->whereDate('started_at', $d);
            } catch (\Throwable $e) {
                // ignore invalid date
            }
        }

        return response()->json(['data' => $q->paginate(20)]);
    }

    public function packages($id)
    {
        $operator = auth('operator')->user();

        $client = \App\Models\Client::where('tenant_id', $operator->tenant_id)->findOrFail($id);

        $items = \App\Models\ClientPackage::with('package')
            ->where('tenant_id', $operator->tenant_id)
            ->where('client_id', $client->id)
            ->orderByDesc('id')
            ->get()
            ->map(function ($cp) {
                return [
                    'id' => $cp->id,
                    'package_id' => $cp->package_id,
                    'status' => $cp->status,
                    'remaining_min' => (int) $cp->remaining_min,
                    'expires_at' => $cp->expires_at,
                    'created_at' => $cp->created_at,
                    'package' => $cp->package ? [
                        'id' => $cp->package->id,
                        'name' => $cp->package->name,
                        'duration_min' => (int) $cp->package->duration_min,
                        'price' => (int) $cp->package->price,
                        'zone' => $cp->package->zone,
                    ] : null,
                ];
            });

        return response()->json(['data' => $items]);
    }

}
