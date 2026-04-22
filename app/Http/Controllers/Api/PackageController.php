<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\PackageIndexRequest;
use App\Http\Requests\Api\StorePackageRequest;
use App\Http\Requests\Api\UpdatePackageRequest;
use App\Http\Resources\Admin\PackageResource;
use App\Services\PackageCatalogService;

class PackageController extends Controller
{
    public function __construct(
        private readonly PackageCatalogService $packages,
    ) {
    }

    public function index(PackageIndexRequest $request)
    {
        $operator = $request->user('operator');
        $packages = $this->packages->paginate(
            (int) $operator->tenant_id,
            $request->filters(),
            $request->perPage(),
        );
        $packages->setCollection(
            collect(PackageResource::collection($packages->getCollection())->resolve())
        );

        return response()->json([
            'data' => $packages,
        ]);
    }

    public function store(StorePackageRequest $request)
    {
        $operator = $request->user('operator');
        $package = $this->packages->create(
            (int) $operator->tenant_id,
            $request->payload(),
        );

        return response()->json([
            'data' => (new PackageResource($package))->resolve(),
        ], 201);
    }

    public function update(UpdatePackageRequest $request, int $id)
    {
        $operator = $request->user('operator');
        $package = $this->packages->update(
            (int) $operator->tenant_id,
            $id,
            $request->payload(),
        );

        return response()->json([
            'data' => (new PackageResource($package))->resolve(),
        ]);
    }

    public function toggle(int $id)
    {
        $tenantId = (int) (auth('operator')->user()?->tenant_id ?? 0);
        $package = $this->packages->toggle($tenantId, $id);

        return response()->json([
            'data' => (new PackageResource($package))->resolve(),
        ]);
    }
}
