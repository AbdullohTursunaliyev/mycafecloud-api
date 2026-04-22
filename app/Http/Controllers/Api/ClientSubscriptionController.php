<?php

namespace App\Http\Controllers\Api;

use App\Enums\PaymentMethod;
use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\ClientSubscription;
use App\Models\SubscriptionPlan;
use App\Services\ClientSubscriptionService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ClientSubscriptionController extends Controller
{
    public function __construct(
        private readonly ClientSubscriptionService $subscriptions,
    ) {
    }

    public function index(Request $request, int $id)
    {
        $operator = $request->user('operator');
        $tenantId = $operator->tenant_id;

        $client = Client::where('tenant_id', $tenantId)->findOrFail($id);

        $this->subscriptions->expireOld($tenantId, (int) $client->id);

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
            'payment_method' => ['required', 'string', Rule::in(PaymentMethod::values())],
            'comment' => ['nullable','string','max:255'],
        ]);

        /** @var Client $client */
        $client = Client::where('tenant_id', $tenantId)->findOrFail($id);

        /** @var SubscriptionPlan $plan */
        $plan = SubscriptionPlan::where('tenant_id', $tenantId)
            ->where('is_active', true)
            ->findOrFail((int)$data['subscription_plan_id']);

        $this->subscriptions->subscribe(
            $tenantId,
            $operator,
            $client,
            $plan,
            (string) $data['payment_method'],
            $data['comment'] ?? null,
        );

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
