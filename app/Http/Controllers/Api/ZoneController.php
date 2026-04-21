<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Zone;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ZoneController extends Controller
{
    private function tenantId()
    {
        // operator auth ishlatyapsiz: auth:operator
        $op = auth('operator')->user();
        return $op ? $op->tenant_id : null;
    }

    public function index(Request $request)
    {
        $tenantId = $this->tenantId();

        $q = Zone::query()
            ->where('tenant_id', $tenantId)
            ->orderBy('is_active', 'desc')
            ->orderBy('name');

        if ($request->has('active') && $request->active !== '') {
            $active = filter_var($request->active, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            if ($active !== null) $q->where('is_active', $active);
        }

        $items = $q->get();

        return response()->json([
            'data' => $items,
        ]);
    }

    public function store(Request $request)
    {
        $tenantId = $this->tenantId();

        $data = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'price_per_hour' => ['required', 'integer', 'min:0', 'max:100000000'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $zone = Zone::create([
            'tenant_id' => $tenantId,
            'name' => $data['name'],
            'price_per_hour' => (int)$data['price_per_hour'],
            'is_active' => array_key_exists('is_active', $data) ? (bool)$data['is_active'] : true,
        ]);

        return response()->json([
            'data' => $zone,
        ], 201);
    }

    public function update(Request $request, $id)
    {
        $tenantId = $this->tenantId();

        $zone = Zone::query()
            ->where('tenant_id', $tenantId)
            ->where('id', $id)
            ->firstOrFail();

        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'min:2', 'max:120'],
            'price_per_hour' => ['sometimes', 'required', 'integer', 'min:0', 'max:100000000'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $zone->fill($data);
        $zone->save();

        return response()->json([
            'data' => $zone,
        ]);
    }

    public function toggle($id)
    {
        $tenantId = $this->tenantId();

        $zone = Zone::query()
            ->where('tenant_id', $tenantId)
            ->where('id', $id)
            ->firstOrFail();

        $zone->is_active = !$zone->is_active;
        $zone->save();

        return response()->json([
            'data' => $zone,
        ]);
    }
}
