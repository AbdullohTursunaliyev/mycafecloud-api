<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SubscriptionPlan;
use Illuminate\Http\Request;

class SubscriptionPlanController extends Controller
{
    public function index(Request $request)
    {
        $operator = $request->user('operator');
        $tenantId = $operator->tenant_id;

        $q = trim((string)$request->get('q', ''));
        $active = $request->get('active', null);
        $zoneId = $request->get('zone_id', null);

        $perPage = (int)$request->get('per_page', 20);
        $perPage = $perPage > 0 ? min($perPage, 200) : 20;

        $query = SubscriptionPlan::query()
            ->where('tenant_id', $tenantId)
            ->with(['zone:id,name'])
            ->orderBy('is_active', 'desc')
            ->orderBy('id', 'desc');

        if ($q !== '') {
            $query->where('name', 'like', "%{$q}%");
        }
        if ($zoneId !== null && $zoneId !== '') {
            $query->where('zone_id', (int)$zoneId);
        }
        if ($active !== null && $active !== '') {
            $query->where('is_active', filter_var($active, FILTER_VALIDATE_BOOLEAN));
        }

        $pag = $query->paginate($perPage);

        return response()->json(['data' => $pag]);
    }

    public function store(Request $request)
    {
        $operator = $request->user('operator');
        $tenantId = $operator->tenant_id;

        $data = $request->validate([
            'name' => ['required','string','min:3','max:120'],
            'zone_id' => ['required','integer'],
            'duration_days' => ['required','integer','min:1','max:3650'],
            'price' => ['required','integer','min:0'],
            'is_active' => ['nullable','boolean'],
        ]);

        $plan = SubscriptionPlan::create([
            'tenant_id' => $tenantId,
            'name' => $data['name'],
            'zone_id' => (int)$data['zone_id'],
            'duration_days' => (int)$data['duration_days'],
            'price' => (int)$data['price'],
            'is_active' => (bool)($data['is_active'] ?? true),
        ]);

        $plan->load(['zone:id,name']);

        return response()->json(['data' => $plan], 201);
    }

    public function update(Request $request, int $id)
    {
        $operator = $request->user('operator');
        $tenantId = $operator->tenant_id;

        $plan = SubscriptionPlan::where('tenant_id', $tenantId)->findOrFail($id);

        $data = $request->validate([
            'name' => ['sometimes','required','string','min:3','max:120'],
            'zone_id' => ['sometimes','required','integer'],
            'duration_days' => ['sometimes','required','integer','min:1','max:3650'],
            'price' => ['sometimes','required','integer','min:0'],
            'is_active' => ['sometimes','required','boolean'],
        ]);

        $plan->fill($data);
        $plan->save();

        $plan->load(['zone:id,name']);

        return response()->json(['data' => $plan]);
    }

    public function toggle(Request $request, int $id)
    {
        $operator = $request->user('operator');
        $tenantId = $operator->tenant_id;

        $plan = SubscriptionPlan::where('tenant_id', $tenantId)->findOrFail($id);
        $plan->is_active = !$plan->is_active;
        $plan->save();

        return response()->json(['data' => $plan]);
    }
}
