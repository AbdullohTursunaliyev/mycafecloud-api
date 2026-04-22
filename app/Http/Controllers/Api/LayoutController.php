<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\BatchUpdateLayoutCellsRequest;
use App\Http\Requests\Api\UpdateLayoutGridRequest;
use App\Http\Resources\Layout\LayoutCellResource;
use App\Http\Resources\Layout\LayoutIndexResource;
use App\Services\LayoutAdminService;
use Illuminate\Http\Request;

class LayoutController extends Controller
{
    public function __construct(
        private readonly LayoutAdminService $layout,
    ) {
    }

    public function index(Request $request)
    {
        $tenantId = (int) $request->user()->tenant_id;
        $payload = $this->layout->index($tenantId);
        $payload['data'] = LayoutCellResource::collection(collect($payload['data']))->resolve();

        return (new LayoutIndexResource($payload))->response();
    }

    public function updateGrid(UpdateLayoutGridRequest $request)
    {
        $tenantId = (int) $request->user()->tenant_id;
        $grid = $this->layout->updateGrid($tenantId, $request->grid());

        return response()->json(['ok' => true, 'grid' => $grid]);
    }

    public function batchUpdate(BatchUpdateLayoutCellsRequest $request)
    {
        $tenantId = (int) $request->user()->tenant_id;
        $this->layout->batchUpdate($tenantId, $request->items());

        return response()->json(['ok' => true]);
    }
}
