<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\StoreReturnRequest;
use App\Services\ReturnService;
use Illuminate\Http\Request;

class ReturnController extends Controller
{
    public function __construct(
        private readonly ReturnService $returns,
    ) {
    }

    public function index(Request $request)
    {
        $tenantId = (int) $request->user()->tenant_id;
        $shiftId = $request->filled('shift_id') ? (int) $request->input('shift_id') : null;

        return response()->json([
            'data' => $this->returns->index($tenantId, $shiftId),
        ]);
    }

    public function options(Request $request, int $id)
    {
        return response()->json([
            'data' => $this->returns->options((int) $request->user()->tenant_id, $id),
        ]);
    }

    public function store(StoreReturnRequest $request, int $id)
    {
        $operator = $request->user();
        $return = $this->returns->store(
            (int) $operator->tenant_id,
            $id,
            (int) $operator->id,
            $request->returnType(),
            $request->sourceId(),
        );

        return response()->json([
            'data' => [
                'return' => $return,
            ],
        ]);
    }
}
