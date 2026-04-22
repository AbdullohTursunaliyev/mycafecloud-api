<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\StoreClientTransferRequest;
use App\Services\ClientTransferService;
use Illuminate\Http\Request;

class TransferController extends Controller
{
    public function __construct(
        private readonly ClientTransferService $transfers,
    ) {
    }

    public function index(Request $request)
    {
        $tenantId = (int) $request->user()->tenant_id;
        $shiftId = $request->filled('shift_id') ? (int) $request->input('shift_id') : null;

        return response()->json([
            'data' => $this->transfers->paginate($tenantId, $shiftId),
        ]);
    }

    public function store(StoreClientTransferRequest $request, int $id)
    {
        $operator = $request->user();

        return response()->json([
            'data' => $this->transfers->transfer(
                (int) $operator->tenant_id,
                $operator,
                $id,
                $request->toClientId(),
                $request->amount(),
            ),
        ], 201);
    }
}
