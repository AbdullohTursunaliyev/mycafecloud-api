<?php

namespace App\Actions\Deployment;

use App\Services\AgentInstallerBuilder;
use App\ValueObjects\Deployment\BulkQuickInstallResult;
use App\ValueObjects\Deployment\QuickInstallCode;

class CreateBulkQuickInstallAction
{
    public function __construct(
        private readonly CreateQuickInstallAction $quickInstall,
        private readonly AgentInstallerBuilder $installerBuilder,
    ) {
    }

    public function execute(
        int $tenantId,
        string $baseUrl,
        int $count,
        ?int $zoneId = null,
        ?string $zoneName = null,
        ?int $expiresInMin = null,
    ): BulkQuickInstallResult {
        $apiBase = rtrim($baseUrl, '/') . '/api';
        $codes = [];

        for ($i = 0; $i < $count; $i++) {
            $result = $this->quickInstall->execute(
                tenantId: $tenantId,
                baseUrl: $baseUrl,
                pcId: null,
                zoneId: $zoneId,
                zoneName: $zoneName,
                expiresInMin: $expiresInMin,
            );

            $codes[] = new QuickInstallCode(
                pairCode: $result->pairCode,
                zone: $result->zone,
                expiresAt: $result->expiresAt,
            );
        }

        return new BulkQuickInstallResult(
            count: count($codes),
            codes: $codes,
            installerScriptUrlPattern: $apiBase . '/deployment/quick-install/{PAIR_CODE}/script.ps1',
            installOneLinerPattern: $this->installerBuilder->buildInstallOneLiner($apiBase . '/deployment/quick-install/{PAIR_CODE}/script.ps1'),
            gpoScriptUrlPattern: $apiBase . '/deployment/quick-install/{PAIR_CODE}/gpo.ps1',
            gpoOneLinerPattern: $this->installerBuilder->buildInstallOneLiner($apiBase . '/deployment/quick-install/{PAIR_CODE}/gpo.ps1'),
        );
    }
}
