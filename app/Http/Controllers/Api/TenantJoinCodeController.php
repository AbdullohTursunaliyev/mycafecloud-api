<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\Admin\TenantJoinCodeResource;
use App\Services\TenantJoinCodeService;
use Illuminate\Http\Request;

class TenantJoinCodeController extends Controller
{
    public function __construct(
        private readonly TenantJoinCodeService $joinCodes,
    ) {
    }

    public function refresh(Request $request)
    {
        $operator = $request->user('operator') ?? $request->user();
        $tenant = $operator->tenant;

        $payload = $this->joinCodes->refresh($tenant);

        return response()->json([
            'data' => (new TenantJoinCodeResource($payload))->resolve(),
        ]);
    }
}
