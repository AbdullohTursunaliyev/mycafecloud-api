<?php

namespace App\Actions\Agent;

use App\Models\Session;
use App\Services\ClientSessionService;
use App\Services\SessionStartService;

class StartAgentSessionAction
{
    public function __construct(
        private readonly ClientSessionService $sessions,
        private readonly SessionStartService $startService,
    ) {
    }

    public function execute(int $tenantId, int $pcId, int $clientId, int $tariffId): Session
    {
        $pc = $this->startService->resolvePc($tenantId, $pcId);
        $client = $this->startService->resolveClient($tenantId, $clientId);
        $tariff = $this->startService->resolveTariff($tenantId, $tariffId);

        $this->startService->ensureClientCanStart($client, 'client');
        $this->startService->ensureTariffMatchesPc($pc, $tariff, 'tariff');
        $this->startService->ensureWalletCanStart($client, $tariff, 'balance');

        return $this->sessions->startAgentSession($tenantId, $pc, $client, $tariff, now());
    }
}
