<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\Mobile\MobileClientSummaryResource;
use App\Http\Resources\Mobile\MobileMissionClaimResource;
use App\Services\MobileClientSummaryService;
use Illuminate\Http\Request;

class MobileClientController extends Controller
{
    public function __construct(
        private readonly MobileClientSummaryService $summary,
    ) {
    }

    // GET /api/mobile/client/summary
    public function summary(Request $request)
    {
        $tenantId = (int)$request->attributes->get('tenant_id');
        $clientId = (int)$request->attributes->get('client_id');

        return new MobileClientSummaryResource($this->summary->buildSummary($tenantId, $clientId));
    }

    // POST /api/mobile/client/missions/{code}/claim
    public function claimMission(Request $request, string $code)
    {
        $tenantId = (int)$request->attributes->get('tenant_id');
        $clientId = (int)$request->attributes->get('client_id');
        return new MobileMissionClaimResource($this->summary->claimMission($tenantId, $clientId, $code));
    }
}
