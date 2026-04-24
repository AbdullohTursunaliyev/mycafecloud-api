<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\Agent\ShellBannerManifestResource;
use App\Services\ShellBannerManifestService;
use Illuminate\Http\Request;

class ShellBannerManifestController extends Controller
{
    public function __construct(
        private readonly ShellBannerManifestService $manifest,
    ) {
    }

    public function index(Request $request)
    {
        $tenantId = (int) $request->attributes->get('tenant_id');
        $pcId = (int) $request->attributes->get('pc_id');

        $payload = [
            'data' => ShellBannerManifestResource::collection($this->manifest->listForPc($tenantId, $pcId))->resolve(),
        ];

        $rotation = $request->attributes->get('rotated_device_token');
        if (is_array($rotation)) {
            $payload['device_token'] = $rotation['plain'];
            $payload['device_token_expires_at'] = optional($rotation['token']->expires_at)->toIso8601String();
        }

        return response()->json($payload);
    }
}
