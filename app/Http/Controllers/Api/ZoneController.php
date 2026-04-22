<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\StoreZoneRequest;
use App\Http\Requests\Api\UpdateZoneRequest;
use App\Http\Requests\Api\ZoneIndexRequest;
use App\Http\Resources\Admin\ZoneResource;
use App\Services\ZoneCatalogService;

class ZoneController extends Controller
{
    public function __construct(
        private readonly ZoneCatalogService $zones,
    ) {
    }

    public function index(ZoneIndexRequest $request)
    {
        $tenantId = (int) ($request->user('operator')?->tenant_id ?? $request->user()?->tenant_id ?? 0);
        $items = ZoneResource::collection(
            $this->zones->list($tenantId, $request->filters())
        )->resolve();

        return response()->json([
            'data' => $items,
        ]);
    }

    public function store(StoreZoneRequest $request)
    {
        $tenantId = (int) ($request->user('operator')?->tenant_id ?? $request->user()?->tenant_id ?? 0);
        $zone = $this->zones->create($tenantId, $request->payload());

        return response()->json([
            'data' => (new ZoneResource($zone))->resolve(),
        ], 201);
    }

    public function update(UpdateZoneRequest $request, int $id)
    {
        $tenantId = (int) ($request->user('operator')?->tenant_id ?? $request->user()?->tenant_id ?? 0);
        $zone = $this->zones->update($tenantId, $id, $request->payload());

        return response()->json([
            'data' => (new ZoneResource($zone))->resolve(),
        ]);
    }

    public function toggle(int $id)
    {
        $tenantId = (int) (auth('operator')->user()?->tenant_id ?? 0);
        $zone = $this->zones->toggle($tenantId, $id);

        return response()->json([
            'data' => (new ZoneResource($zone))->resolve(),
        ]);
    }
}
