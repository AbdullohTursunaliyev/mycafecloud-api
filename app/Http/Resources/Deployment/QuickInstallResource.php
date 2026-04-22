<?php

namespace App\Http\Resources\Deployment;

use App\Http\Resources\BaseJsonResource;
use App\ValueObjects\Deployment\QuickInstallResult;

class QuickInstallResource extends BaseJsonResource
{
    /**
     * @var QuickInstallResult
     */
    public $resource;

    public function toArray($request): array
    {
        return [
            'data' => [
                'pair_code' => $this->resource->pairCode,
                'zone' => $this->resource->zone,
                'pc_id' => $this->resource->pcId,
                'expires_at' => $this->resource->expiresAt->toIso8601String(),
                'pair_endpoint' => $this->resource->pairEndpoint,
                'installer_script_url' => $this->resource->installerScriptUrl,
                'install_one_liner' => $this->resource->installOneLiner,
                'gpo_script_url' => $this->resource->gpoScriptUrl,
                'gpo_one_liner' => $this->resource->gpoOneLiner,
                'installer_script' => $this->resource->installerScript,
                'quick_test_curl' => $this->resource->quickTestCurl,
                'powershell_example' => $this->resource->powershellExample,
            ],
        ];
    }
}
