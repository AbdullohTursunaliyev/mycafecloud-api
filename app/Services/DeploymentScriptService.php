<?php

namespace App\Services;

use App\Models\PcPairCode;
use Illuminate\Validation\ValidationException;

class DeploymentScriptService
{
    public function __construct(
        private readonly AgentInstallerBuilder $installerBuilder,
    ) {
    }

    public function buildPrivateInstallerScript(int $tenantId, string $code, string $baseUrl): array
    {
        $pair = $this->findValidPairCode($code);
        if ((int) $pair->tenant_id !== $tenantId) {
            throw ValidationException::withMessages([
                'code' => 'Pair code does not belong to current tenant.',
            ]);
        }

        return $this->buildInstallerPayload((int) $pair->tenant_id, $pair->code, $baseUrl);
    }

    public function buildPublicInstallerScript(string $code, string $baseUrl): array
    {
        $pair = $this->findValidPairCode($code);

        return $this->buildInstallerPayload((int) $pair->tenant_id, $pair->code, $baseUrl);
    }

    public function buildPublicGpoScript(string $code, string $baseUrl): array
    {
        $pair = $this->findValidPairCode($code);
        $apiBase = rtrim($baseUrl, '/') . '/api';
        $scriptUrl = $apiBase . '/deployment/quick-install/' . urlencode($pair->code) . '/script.ps1';

        return [
            'script' => $this->installerBuilder->buildGpoScript($scriptUrl),
            'filename' => 'mycafecloud-gpo-' . strtolower($pair->code) . '.ps1',
        ];
    }

    private function buildInstallerPayload(int $tenantId, string $code, string $baseUrl): array
    {
        $apiBase = rtrim($baseUrl, '/') . '/api';
        $script = $this->installerBuilder->buildInstallerScript(
            $this->installerBuilder->buildInstallerConfig($tenantId, $apiBase, $code)
        );

        return [
            'script' => $script,
            'filename' => 'mycafecloud-install-' . strtolower($code) . '.ps1',
        ];
    }

    private function findValidPairCode(string $code): PcPairCode
    {
        $code = strtoupper(trim($code));
        if (!preg_match('/^[A-Z0-9]{4}-[A-Z0-9]{2}$/', $code)) {
            throw ValidationException::withMessages([
                'code' => 'Invalid pair code format.',
            ]);
        }

        $pair = PcPairCode::query()->where('code', $code)->firstOrFail();
        if ($pair->used_at) {
            throw ValidationException::withMessages([
                'code' => 'Pair code already used.',
            ]);
        }
        if ($pair->expires_at && $pair->expires_at->lte(now())) {
            throw ValidationException::withMessages([
                'code' => 'Pair code expired.',
            ]);
        }

        return $pair;
    }
}
