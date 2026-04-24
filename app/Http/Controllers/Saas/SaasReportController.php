<?php

namespace App\Http\Controllers\Saas;

use App\Http\Controllers\Controller;
use App\Services\SaasReportService;

class SaasReportController extends Controller
{
    public function __construct(
        private readonly SaasReportService $reports,
    ) {
    }

    public function overview()
    {
        return response()->json([
            'data' => $this->reports->overview(),
        ]);
    }
}
