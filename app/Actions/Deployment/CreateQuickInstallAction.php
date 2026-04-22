<?php

namespace App\Actions\Deployment;

use App\Models\Pc;
use App\Models\PcPairCode;
use App\Models\Zone;
use App\Services\AgentInstallerBuilder;
use App\ValueObjects\Deployment\QuickInstallResult;
use Illuminate\Support\Str;

class CreateQuickInstallAction
{
    public function __construct(
        private readonly AgentInstallerBuilder $installerBuilder,
    ) {
    }

    public function execute(
        int $tenantId,
        string $baseUrl,
        ?int $pcId = null,
        ?int $zoneId = null,
        ?string $zoneName = null,
        ?int $expiresInMin = null,
    ): QuickInstallResult {
        $resolvedZone = $this->resolveZoneName($tenantId, $pcId, $zoneId, $zoneName);
        $resolvedPcId = $this->resolvePcId($tenantId, $pcId);

        $pair = $this->createPairCodeRecord(
            $tenantId,
            $resolvedZone,
            (int) ($expiresInMin ?? config('domain.pc.pair_code.default_ttl_minutes', 10)),
            $resolvedPcId,
        );

        $apiBase = rtrim($baseUrl, '/') . '/api';
        $scriptUrl = $apiBase . '/deployment/quick-install/' . urlencode($pair->code) . '/script.ps1';
        $gpoUrl = $apiBase . '/deployment/quick-install/' . urlencode($pair->code) . '/gpo.ps1';
        $script = $this->installerBuilder->buildInstallerScript(
            $this->installerBuilder->buildInstallerConfig($tenantId, $apiBase, $pair->code)
        );

        return new QuickInstallResult(
            pairCode: (string) $pair->code,
            zone: $pair->zone ? (string) $pair->zone : null,
            pcId: $pair->pc_id ? (int) $pair->pc_id : null,
            expiresAt: $pair->expires_at,
            pairEndpoint: $apiBase . '/agent/pair',
            installerScriptUrl: $scriptUrl,
            installOneLiner: $this->installerBuilder->buildInstallOneLiner($scriptUrl),
            gpoScriptUrl: $gpoUrl,
            gpoOneLiner: $this->installerBuilder->buildInstallOneLiner($gpoUrl),
            installerScript: $script,
            quickTestCurl: sprintf(
                "curl -X POST \"%s/agent/pair\" -H \"Content-Type: application/json\" -d '{\"pair_code\":\"%s\",\"pc_name\":\"TEST-PC\"}'",
                $apiBase,
                $pair->code
            ),
            powershellExample: sprintf(
                '$server="%s"; $code="%s"; $payload=@{pair_code=$code; pc_name=$env:COMPUTERNAME} | ConvertTo-Json; Invoke-RestMethod -Method Post -Uri "$server/agent/pair" -ContentType "application/json" -Body $payload',
                $apiBase,
                $pair->code
            ),
        );
    }

    private function resolvePcId(int $tenantId, ?int $pcId): ?int
    {
        if ($pcId === null) {
            return null;
        }

        return (int) Pc::query()
            ->where('tenant_id', $tenantId)
            ->findOrFail($pcId)
            ->id;
    }

    private function resolveZoneName(int $tenantId, ?int $pcId, ?int $zoneId, ?string $zoneName): ?string
    {
        $zoneName = is_string($zoneName) ? trim($zoneName) : null;
        if ($zoneName !== null && $zoneName !== '') {
            return $zoneName;
        }

        if ($zoneId !== null) {
            return Zone::query()
                ->where('tenant_id', $tenantId)
                ->where('id', $zoneId)
                ->value('name');
        }

        if ($pcId === null) {
            return null;
        }

        $pc = Pc::query()
            ->where('tenant_id', $tenantId)
            ->findOrFail($pcId);

        $pcZone = trim((string) ($pc->zone ?: ''));
        if ($pcZone !== '') {
            return $pcZone;
        }

        if ($pc->zone_id) {
            return Zone::query()
                ->where('tenant_id', $tenantId)
                ->where('id', (int) $pc->zone_id)
                ->value('name');
        }

        return null;
    }

    private function createPairCodeRecord(int $tenantId, ?string $zoneName, int $expiresInMin, ?int $pcId = null): PcPairCode
    {
        return PcPairCode::query()->create([
            'tenant_id' => $tenantId,
            'code' => $this->generatePairCode(),
            'zone' => $zoneName,
            'expires_at' => now()->addMinutes($expiresInMin),
            'pc_id' => $pcId,
        ]);
    }

    private function generatePairCode(): string
    {
        do {
            $code = Str::upper(Str::random(4)) . '-' . Str::upper(Str::random(2));
        } while (PcPairCode::query()->where('code', $code)->exists());

        return $code;
    }
}
