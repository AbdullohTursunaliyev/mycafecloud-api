<?php

namespace App\Http\Resources\Deployment;

use App\Http\Resources\BaseJsonResource;
use App\ValueObjects\Deployment\BulkQuickInstallResult;
use App\ValueObjects\Deployment\QuickInstallCode;

class BulkQuickInstallResource extends BaseJsonResource
{
    /**
     * @var BulkQuickInstallResult
     */
    public $resource;

    public function toArray($request): array
    {
        return [
            'data' => [
                'count' => $this->resource->count,
                'codes' => array_map(
                    static fn(QuickInstallCode $code) => [
                        'pair_code' => $code->pairCode,
                        'zone' => $code->zone,
                        'expires_at' => $code->expiresAt->toIso8601String(),
                    ],
                    $this->resource->codes,
                ),
                'installer_script_url_pattern' => $this->resource->installerScriptUrlPattern,
                'install_one_liner_pattern' => $this->resource->installOneLinerPattern,
                'gpo_script_url_pattern' => $this->resource->gpoScriptUrlPattern,
                'gpo_one_liner_pattern' => $this->resource->gpoOneLinerPattern,
            ],
        ];
    }
}
