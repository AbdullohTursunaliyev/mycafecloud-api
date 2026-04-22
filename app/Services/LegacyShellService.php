<?php

namespace App\Services;

use App\Models\Client;
use App\Models\Pc;
use App\Models\Session;

class LegacyShellService
{
    public function __construct(
        private readonly ClientShellContextService $context,
        private readonly ClientSessionService $sessions,
        private readonly SessionBillingService $billing,
    ) {
    }

    public function sessionState(string $licenseKey, string $pcCode): array
    {
        $license = $this->context->resolveLicenseForShell($licenseKey);
        $tenantId = (int) $license->tenant_id;
        $pc = $this->context->resolvePcForShell($tenantId, $pcCode);

        $pcView = $this->sessions->describePc($pc);

        $session = Session::query()
            ->where('tenant_id', $tenantId)
            ->where('pc_id', $pc->id)
            ->where('status', 'active')
            ->latest('id')
            ->first();

        if (!$session) {
            return [
                'has_session' => false,
                'pc' => [
                    'id' => $pc->id,
                    'code' => $pc->code,
                    'zone' => $pcView['zone'],
                    'status' => $pc->status,
                ],
                'zone' => [
                    'name' => $pcView['zone'],
                    'price_per_hour' => $pcView['rate_per_hour'],
                ],
            ];
        }

        try {
            $this->billing->billSingleSession($session);
            $session->refresh();
        } catch (\Throwable) {
            // Legacy shell status should stay available even if billing tick fails.
        }

        if ($session->status !== 'active' || $session->ended_at) {
            return [
                'has_session' => false,
                'pc' => [
                    'id' => $pc->id,
                    'code' => $pc->code,
                    'zone' => $pcView['zone'],
                    'status' => $pc->status,
                ],
                'zone' => [
                    'name' => $pcView['zone'],
                    'price_per_hour' => $pcView['rate_per_hour'],
                ],
            ];
        }

        $client = Client::query()
            ->where('tenant_id', $tenantId)
            ->find($session->client_id);

        $sessionView = $client
            ? $this->sessions->describeSession($session, $client, $pc)
            : ['seconds_left' => 0];

        return [
            'has_session' => true,
            'pc' => [
                'id' => $pc->id,
                'code' => $pc->code,
                'zone' => $pcView['zone'],
                'status' => $pc->status,
            ],
            'zone' => [
                'name' => $pcView['zone'],
                'price_per_hour' => $pcView['rate_per_hour'],
            ],
            'client' => $client ? [
                'id' => (int) $client->id,
                'login' => $client->login,
                'balance' => (int) $client->balance,
                'bonus' => (int) $client->bonus,
            ] : null,
            'session' => [
                'id' => (int) $session->id,
                'status' => (string) $session->status,
                'started_at' => (string) $session->started_at,
                'is_package' => (bool) $session->is_package,
            ],
            'left_seconds' => (int) ($sessionView['seconds_left'] ?? 0),
        ];
    }

    public function logout(string $licenseKey, string $pcCode): void
    {
        $license = $this->context->resolveLicenseForShell($licenseKey);
        $tenantId = (int) $license->tenant_id;
        $pc = $this->context->resolvePcForShell($tenantId, $pcCode);

        $this->sessions->logoutActiveSessionFromPc($tenantId, $pc);
    }
}
