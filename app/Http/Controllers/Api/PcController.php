<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\PcIndexRequest;
use App\Http\Requests\Api\PcLayoutBatchUpdateRequest;
use App\Http\Requests\Api\StorePcRequest;
use App\Http\Requests\Api\UpdatePcRequest;
use App\Services\PcAdminService;
use Illuminate\Http\Request;

class PcController extends Controller
{
    public function __construct(
        private readonly PcAdminService $pcs,
    ) {
    }

    public function index(PcIndexRequest $request)
    {
        return response()->json([
            'data' => $this->pcs->list((int) $request->user()->tenant_id, $request->filters()),
        ]);
    }

    public function store(StorePcRequest $request)
    {
        return response()->json([
            'data' => $this->pcs->create((int) $request->user()->tenant_id, $request->payload()),
        ], 201);
    }

    public function update(UpdatePcRequest $request, int $id)
    {
        return response()->json([
            'data' => $this->pcs->update((int) $request->user()->tenant_id, $id, $request->payload()),
        ]);
    }

    public function destroy(Request $request, int $id)
    {
        $this->pcs->delete((int) $request->user()->tenant_id, $id);

        return response()->json(['ok' => true]);
    }

    public function layoutBatchUpdate(PcLayoutBatchUpdateRequest $request)
    {
        $this->pcs->layoutBatchUpdate((int) $request->user()->tenant_id, $request->items());

        return response()->json(['ok' => true]);
    }
}
