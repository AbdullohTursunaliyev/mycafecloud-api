<?php

namespace App\Actions\ClientAuth;

use App\Services\ClientSessionService;
use App\Services\ClientShellContextService;
use App\Services\PcCommandDispatchService;
use App\ValueObjects\ClientAuth\ClientShellStateResult;

class GetClientShellStateAction
{
    public function __construct(
        private readonly ClientShellContextService $context,
        private readonly ClientSessionService $sessions,
        private readonly PcCommandDispatchService $commands,
    ) {
    }

    public function execute(string $licenseKey, string $pcCode, ?string $bearer): ClientShellStateResult
    {
        $license = $this->context->resolveLicenseForShell($licenseKey);
        $tenantId = (int) $license->tenant_id;
        $resolved = $this->context->resolveShellClient($bearer, $tenantId, true);
        $client = $resolved['client'];
        $pc = $this->context->resolvePcForShell($tenantId, $pcCode);

        $session = $this->sessions->resolveOwnedActiveSession($tenantId, $pc, $client);
        $pcView = $this->sessions->describePc($pc);

        if ($session && ($session->status !== 'active' || $session->ended_at)) {
            $session = null;
        }

        $secondsLeft = 0;
        $from = 'balance';
        if ($session) {
            $sessionView = $this->sessions->describeSession($session, $client, $pc);
            $secondsLeft = (int) ($sessionView['seconds_left'] ?? 0);
            $from = (string) ($sessionView['from'] ?? 'balance');
        }

        return new ClientShellStateResult(
            locked: !$session,
            client: [
                'id' => (int) $client->id,
                'login' => $client->login,
                'phone' => $client->phone,
                'username' => $client->username,
                'balance' => (int) $client->balance,
                'bonus' => (int) $client->bonus,
                'pc' => $pc->code,
            ],
            pc: [
                'code' => $pc->code,
                'zone' => $pcView['zone'],
                'rate_per_hour' => $pcView['rate_per_hour'],
            ],
            session: [
                'id' => $session?->id ? (int) $session->id : null,
                'status' => $session?->status,
                'started_at' => $session?->started_at,
                'seconds_left' => $secondsLeft,
                'from' => $from,
            ],
            command: $this->commands->deliverNextPendingCommand(
                $tenantId,
                (int) $pc->id,
                $session?->started_at,
            ),
            billingOptions: $this->sessions->describeBillingOptions($tenantId, $client, $pc),
        );
    }
}
