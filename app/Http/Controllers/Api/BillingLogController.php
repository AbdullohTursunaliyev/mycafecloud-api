<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\BillingLogIndexRequest;
use App\Services\BillingLogService;

class BillingLogController extends Controller
{
    public function __construct(
        private readonly BillingLogService $billing,
    ) {
    }

    public function index(BillingLogIndexRequest $request)
    {
        $tenantId = (int) $request->user()->tenant_id;
        $filters = $request->filters();

        if ($request->wantsSummary()) {
            return response()->json([
                'data' => $this->billing->summary($tenantId, $filters),
            ]);
        }

        return response()->json(
            $this->billing->paginate($tenantId, $filters, $request->perPage())
        );
    }
}
