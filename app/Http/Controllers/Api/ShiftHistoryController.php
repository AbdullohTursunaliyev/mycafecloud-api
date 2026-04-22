<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\ShiftHistoryExportRequest;
use App\Http\Resources\Shift\ShiftHistoryIndexResource;
use App\Http\Resources\Shift\ShiftHistoryShowResource;
use App\Services\ShiftHistoryExportService;
use App\Services\ShiftHistoryService;
use Carbon\Carbon;
use Illuminate\Http\Request;

class ShiftHistoryController extends Controller
{
    public function __construct(
        private readonly ShiftHistoryService $history,
        private readonly ShiftHistoryExportService $exports,
    ) {
    }

    public function index(Request $request)
    {
        $tenantId = (int) $request->user()->tenant_id;

        $data = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:5', 'max:50'],
        ]);

        $perPage = (int) ($data['per_page'] ?? 10);
        $from = !empty($data['from']) ? Carbon::parse($data['from'])->startOfDay() : null;
        $to = !empty($data['to']) ? Carbon::parse($data['to'])->endOfDay() : null;

        return new ShiftHistoryIndexResource(
            $this->history->paginateHistory($tenantId, $from, $to, $perPage)
        );
    }

    public function show(Request $request, int $id)
    {
        return new ShiftHistoryShowResource(
            $this->history->show((int) $request->user()->tenant_id, $id)
        );
    }

    public function export(ShiftHistoryExportRequest $request)
    {
        return $this->exports->exportXmlResponse(
            (int) $request->user()->tenant_id,
            $request->fromDate(),
            $request->toDate(),
            $request->lang(),
            $request->limit(),
        );
    }

    public function exportXlsx(ShiftHistoryExportRequest $request)
    {
        return $this->exports->exportXlsxResponse(
            (int) $request->user()->tenant_id,
            $request->fromDate(),
            $request->toDate(),
            $request->lang(),
            $request->limit(),
        );
    }
}
