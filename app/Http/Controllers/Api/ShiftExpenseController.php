<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\CurrentShiftExpensesRequest;
use App\Http\Requests\Api\StoreShiftExpenseRequest;
use App\Http\Resources\Shift\CurrentShiftExpensesResource;
use App\Http\Resources\Shift\ShiftExpenseResource;
use App\Services\ShiftExpenseService;
use Illuminate\Http\Request;

class ShiftExpenseController extends Controller
{
    public function __construct(
        private readonly ShiftExpenseService $expenses,
    ) {
    }

    public function current(CurrentShiftExpensesRequest $request)
    {
        $tenantId = (int) ($request->user('operator')?->tenant_id ?? $request->user()?->tenant_id ?? 0);
        $result = $this->expenses->current($tenantId, $request->limitValue());
        $result['items'] = ShiftExpenseResource::collection(collect($result['items']))->resolve();

        return (new CurrentShiftExpensesResource($result))->response();
    }

    public function store(StoreShiftExpenseRequest $request)
    {
        $tenantId = (int) ($request->user('operator')?->tenant_id ?? $request->user()?->tenant_id ?? 0);
        $operatorId = (int) ($request->user('operator')?->id ?? $request->user()?->id ?? 0);

        $expense = $this->expenses->store(
            $tenantId,
            $operatorId,
            $request->payload(),
        );

        return response()->json([
            'data' => (new ShiftExpenseResource($expense))->resolve(),
        ], 201);
    }

    public function destroy(Request $request, int $id)
    {
        $tenantId = (int) ($request->user('operator')?->tenant_id ?? $request->user()?->tenant_id ?? 0);
        $this->expenses->destroy($tenantId, $id);

        return response()->json(['ok' => true]);
    }
}
