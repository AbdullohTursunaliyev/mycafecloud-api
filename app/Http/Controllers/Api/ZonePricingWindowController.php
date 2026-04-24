<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\StoreZonePricingWindowRequest;
use App\Http\Requests\Api\UpdateZonePricingWindowRequest;
use App\Http\Requests\Api\ZonePricingWindowIndexRequest;
use App\Http\Resources\Admin\ZonePricingWindowResource;
use App\Services\ZonePricingWindowCatalogService;

class ZonePricingWindowController extends Controller
{
    public function __construct(
        private readonly ZonePricingWindowCatalogService $windows,
    ) {
    }

    public function index(ZonePricingWindowIndexRequest $request, int $zoneId)
    {
        $tenantId = (int) ($request->user('operator')?->tenant_id ?? $request->user()?->tenant_id ?? 0);
        $items = ZonePricingWindowResource::collection(
            $this->windows->list($tenantId, $zoneId, $request->filters())
        )->resolve();

        return response()->json([
            'data' => $items,
        ]);
    }

    public function store(StoreZonePricingWindowRequest $request, int $zoneId)
    {
        $tenantId = (int) ($request->user('operator')?->tenant_id ?? $request->user()?->tenant_id ?? 0);
        $window = $this->windows->create($tenantId, $zoneId, $request->payload());

        return response()->json([
            'data' => (new ZonePricingWindowResource($window))->resolve(),
        ], 201);
    }

    public function update(UpdateZonePricingWindowRequest $request, int $zoneId, int $windowId)
    {
        $tenantId = (int) ($request->user('operator')?->tenant_id ?? $request->user()?->tenant_id ?? 0);
        $window = $this->windows->update($tenantId, $zoneId, $windowId, $request->payload());

        return response()->json([
            'data' => (new ZonePricingWindowResource($window))->resolve(),
        ]);
    }

    public function toggle(int $zoneId, int $windowId)
    {
        $tenantId = (int) (auth('operator')->user()?->tenant_id ?? 0);
        $window = $this->windows->toggle($tenantId, $zoneId, $windowId);

        return response()->json([
            'data' => (new ZonePricingWindowResource($window))->resolve(),
        ]);
    }

    public function destroy(int $zoneId, int $windowId)
    {
        $tenantId = (int) (auth('operator')->user()?->tenant_id ?? 0);
        $this->windows->delete($tenantId, $zoneId, $windowId);

        return response()->json([
            'ok' => true,
        ]);
    }
}
