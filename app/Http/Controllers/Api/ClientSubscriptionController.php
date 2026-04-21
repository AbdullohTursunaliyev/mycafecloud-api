<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\ClientSubscription;
use App\Models\ClientTransaction;
use App\Models\Shift;
use App\Models\SubscriptionPlan;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ClientSubscriptionController extends Controller
{
    private function expireOld(int $tenantId, int $clientId): void
    {
        ClientSubscription::query()
            ->where('tenant_id', $tenantId)
            ->where('client_id', $clientId)
            ->where('status', 'active')
            ->whereNotNull('ends_at')
            ->where('ends_at', '<=', now())
            ->update(['status' => 'expired', 'updated_at' => now()]);
    }

    public function index(Request $request, int $id)
    {
        $operator = $request->user('operator');
        $tenantId = $operator->tenant_id;

        $client = Client::where('tenant_id', $tenantId)->findOrFail($id);

        $this->expireOld($tenantId, $client->id);

        $onlyActive = $request->get('active', null);
        $perPage = (int)$request->get('per_page', 20);
        $perPage = $perPage > 0 ? min($perPage, 200) : 20;

        $q = ClientSubscription::query()
            ->where('tenant_id', $tenantId)
            ->where('client_id', $client->id)
            ->with([
                'plan:id,name,zone_id,duration_days,price,is_active',
                'zone:id,name',
            ])
            ->orderByRaw("CASE WHEN status='active' THEN 0 ELSE 1 END")
            ->orderBy('ends_at', 'desc')
            ->orderBy('id', 'desc');

        if ($onlyActive !== null && $onlyActive !== '') {
            if (filter_var($onlyActive, FILTER_VALIDATE_BOOLEAN)) {
                $q->where('status', 'active')->where('ends_at', '>', now());
            }
        }

        $pag = $q->paginate($perPage);

        return response()->json(['data' => $pag]);
    }

    public function subscribe(Request $request, int $id)
    {
        $operator = $request->user('operator');
        $tenantId = $operator->tenant_id;

        $data = $request->validate([
            'subscription_plan_id' => ['required','integer'],
            'payment_method' => ['required','string','in:balance,cash,card'],
            'comment' => ['nullable','string','max:255'],
        ]);

        /** @var Client $client */
        $client = Client::where('tenant_id', $tenantId)->findOrFail($id);

        /** @var SubscriptionPlan $plan */
        $plan = SubscriptionPlan::where('tenant_id', $tenantId)
            ->where('is_active', true)
            ->findOrFail((int)$data['subscription_plan_id']);

        $this->expireOld($tenantId, $client->id);

        // ✅ shu zonada active subscription bo‘lsa — block
        $exists = ClientSubscription::query()
            ->where('tenant_id', $tenantId)
            ->where('client_id', $client->id)
            ->where('zone_id', $plan->zone_id)
            ->where('status', 'active')
            ->whereNotNull('ends_at')
            ->where('ends_at', '>', now())
            ->exists();

        if ($exists) {
            return response()->json([
                'message' => 'У клиента уже есть активная подписка в этой зоне. Дождитесь окончания.',
            ], 422);
        }

        $amount = (int)$plan->price;
        $pay = (string)$data['payment_method'];

        DB::transaction(function () use ($tenantId, $operator, $client, $plan, $amount, $pay, $data) {

            // ✅ shift majburiy (cash/card ham, balans ham — audit uchun)
            $shift = Shift::query()
                ->where('tenant_id', $tenantId)
                ->whereNull('closed_at')
                ->latest('id')
                ->first();

            if (!$shift) {
                throw ValidationException::withMessages(['shift' => 'Смена не открыта']);
            }

            // payment handling
            if ($pay === 'balance') {
                if ((int)$client->balance < $amount) {
                    throw ValidationException::withMessages(['balance' => 'Недостаточно средств на балансе клиента']);
                }
                $client->decrement('balance', $amount);
            }

            $starts = now();
            $ends = now()->addDays((int)$plan->duration_days);

            $sub = ClientSubscription::create([
                'tenant_id' => $tenantId,
                'client_id' => $client->id,
                'subscription_plan_id' => $plan->id,
                'zone_id' => $plan->zone_id,
                'status' => 'active',
                'starts_at' => $starts,
                'ends_at' => $ends,
                'payment_method' => $pay,
                'shift_id' => $shift->id,
                'operator_id' => $operator->id,
                'amount' => $amount,
                'meta' => [
                    'plan_name' => $plan->name,
                    'duration_days' => (int)$plan->duration_days,
                ],
            ]);

            // ✅ Shift summary ko‘rishi uchun: ClientTransaction yozamiz
            ClientTransaction::create([
                'tenant_id' => $tenantId,
                'client_id' => $client->id,
                'operator_id' => $operator->id,
                'shift_id' => $shift->id,
                'type' => 'subscription', // <<< YANGI TYPE
                'amount' => $amount,
                'bonus_amount' => 0,
                'payment_method' => $pay,
                'comment' => $data['comment'] ?? null,
                'meta' => [
                    'subscription_id' => $sub->id,
                    'subscription_plan_id' => $plan->id,
                    'zone_id' => $plan->zone_id,
                    'plan_name' => $plan->name,
                    'duration_days' => (int)$plan->duration_days,
                ],
            ]);
        });

        $client->refresh();

        return response()->json([
            'data' => [
                'client' => $client,
            ]
        ]);
    }

    public function cancel(Request $request, int $id, int $subId)
    {
        $operator = $request->user('operator');
        $tenantId = $operator->tenant_id;

        $client = Client::where('tenant_id', $tenantId)->findOrFail($id);

        $sub = ClientSubscription::where('tenant_id', $tenantId)
            ->where('client_id', $client->id)
            ->findOrFail($subId);

        if ($sub->status !== 'active') {
            return response()->json(['data' => $sub]);
        }

        $sub->status = 'canceled';
        $sub->ends_at = now(); // darhol tugaydi
        $sub->save();

        return response()->json(['data' => $sub]);
    }
}
