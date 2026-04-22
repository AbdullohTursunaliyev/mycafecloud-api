<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\Shift\ShiftCurrentSummaryResource;
use App\Http\Resources\Shift\ShiftReportResource;
use Carbon\Carbon;
use Illuminate\Http\Request;
use App\Services\ShiftService;

class ShiftController extends Controller
{
    public function __construct(
        private readonly ShiftService $shifts,
    ) {
    }

    // GET /api/shifts/current
    public function current(Request $request)
    {
        $tenantId = $request->user()->tenant_id;

        return response()->json([
            'data' => $this->shifts->currentShift($tenantId),
        ]);
    }

    // POST /api/shifts/open
    public function open(Request $request)
    {
        $tenantId = $request->user()->tenant_id;
        $operatorId = $request->user()->id;

        $data = $request->validate([
            'opening_cash' => ['required', 'integer', 'min:0'],
        ]);

        $shift = $this->shifts->openShift(
            $tenantId,
            $operatorId,
            (int) $data['opening_cash'],
            now(),
            [],
            $request->user()->login ?? null,
        );

        return response()->json(['data' => $shift], 201);
    }


    // POST /api/shifts/close
    // POST /api/shifts/close
    public function close(Request $request)
    {
        $tenantId   = $request->user()->tenant_id;
        $operatorId = $request->user()->id;

        $data = $request->validate([
            'closing_cash' => ['required', 'integer', 'min:0'],
        ]);

        $shift = $this->shifts->currentShift($tenantId);

        if (!$shift) {
            return response()->json([
                'message' => 'Смена не открыта',
                'errors' => ['shift' => ['Смена не открыта']],
            ], 422);
        }

        $result = $this->shifts->closeShift(
            $tenantId,
            $shift,
            $operatorId,
            (int) $data['closing_cash'],
            now(),
            [],
            $request->user()->login ?? null,
        );

        return response()->json(['data' => $result['shift']]);
    }



    // GET /api/shifts/report?from=YYYY-MM-DD&to=YYYY-MM-DD&limit=10
    public function report(Request $request)
    {
        $tenantId = $request->user()->tenant_id;

        $data = $request->validate([
            'from' => ['required', 'date'],
            'to' => ['required', 'date'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);

        $from = Carbon::parse($data['from'])->startOfDay();
        $to = Carbon::parse($data['to'])->endOfDay();
        $limit = (int)($data['limit'] ?? 10);

        return new ShiftReportResource(
            $this->shifts->buildReport($tenantId, $from, $to, $limit)
        );
    }

    public function currentSummary(Request $request)
    {
        $tenantId = $request->user()->tenant_id;

        $summary = $this->shifts->buildCurrentSummary($tenantId);

        if (!$summary) {
            return response()->json([
                'data' => null,
                'message' => 'Shift is not open'
            ], 200);
        }
        return new ShiftCurrentSummaryResource($summary);
    }

}
