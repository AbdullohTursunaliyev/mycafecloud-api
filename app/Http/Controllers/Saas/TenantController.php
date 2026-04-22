<?php

namespace App\Http\Controllers\Saas;

use App\Http\Controllers\Controller;
use App\Http\Requests\Saas\StoreTenantRequest;
use App\Http\Requests\Saas\TenantIndexRequest;
use App\Http\Requests\Saas\UpdateTenantRequest;
use App\Http\Resources\Saas\TenantResource;
use App\Services\SaasTenantService;

class TenantController extends Controller
{
    public function __construct(
        private readonly SaasTenantService $tenants,
    ) {
    }

    public function index(TenantIndexRequest $request)
    {
        $tenants = $this->tenants->paginate($request->filters());
        $tenants->setCollection(
            collect(TenantResource::collection($tenants->getCollection())->resolve())
        );

        return response()->json(['data' => $tenants]);
    }

    public function store(StoreTenantRequest $request)
    {
        return response()->json([
            'data' => new TenantResource($this->tenants->create($request->payload())),
        ], 201);
    }

    public function show(int $id)
    {
        return response()->json([
            'data' => new TenantResource($this->tenants->show($id)),
        ]);
    }

    public function update(UpdateTenantRequest $request, int $id)
    {
        return response()->json([
            'data' => new TenantResource($this->tenants->update($id, $request->payload())),
        ]);
    }
}
