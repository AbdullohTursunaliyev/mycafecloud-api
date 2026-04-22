<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\CreatePcPairCodeRequest;
use App\Http\Resources\Admin\PcPairCodeResource;
use App\Services\PcPairCodeService;

class PcPairCodeController extends Controller
{
    public function __construct(
        private readonly PcPairCodeService $pairCodes,
    ) {
    }

    public function create(CreatePcPairCodeRequest $request)
    {
        $tenantId = (int) $request->user()->tenant_id;
        $pair = $this->pairCodes->create(
            $tenantId,
            $request->zone(),
            $request->expiresInMinutes(),
        );

        return response()->json([
            'data' => (new PcPairCodeResource($pair))->resolve(),
        ], 201);
    }
}
