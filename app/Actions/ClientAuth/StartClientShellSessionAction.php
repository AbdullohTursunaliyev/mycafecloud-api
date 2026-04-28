<?php

namespace App\Actions\ClientAuth;

use App\Models\Client;
use App\Models\Pc;
use App\Services\ClientSessionService;
use App\Services\ClientShellContextService;
use App\ValueObjects\ClientAuth\ClientShellLoginResult;

class StartClientShellSessionAction
{
    public function __construct(
        private readonly ClientShellContextService $context,
        private readonly ClientSessionService $sessions,
    ) {
    }

    public function execute(
        string $licenseKey,
        string $pcCode,
        ?string $bearer,
        string $source,
        ?int $clientPackageId = null,
    ): ClientShellLoginResult {
        $license = $this->context->resolveLicenseForShell($licenseKey);
        $tenantId = (int) $license->tenant_id;
        $resolved = $this->context->resolveShellClient($bearer, $tenantId, true);
        $client = $resolved['client'];
        $pc = $this->context->resolvePcForShell($tenantId, $pcCode);

        $package = $this->sessions->ensureShellStartAllowed($tenantId, $client, $pc, $source, $clientPackageId);
        $session = $this->sessions->startOrResumeShellSession(
            $tenantId,
            $pc,
            $client,
            $package,
            null,
            $source === 'package',
        );

        return new ClientShellLoginResult(
            token: (string) $bearer,
            client: $this->clientPayload($client->fresh() ?: $client, $pc),
            pc: $this->sessions->describePc($pc),
            session: $this->sessions->describeSession($session, $client->fresh() ?: $client, $pc),
            note: $session->wasRecentlyCreated ? null : 'existing_active_session',
            billingOptions: $this->sessions->describeBillingOptions($tenantId, $client->fresh() ?: $client, $pc),
        );
    }

    private function clientPayload(Client $client, Pc $pc): array
    {
        return [
            'id' => (int) $client->id,
            'account_id' => $client->account_id,
            'login' => $client->login,
            'phone' => $client->phone ?? null,
            'username' => $client->username ?? null,
            'balance' => (int) $client->balance,
            'bonus' => (int) $client->bonus,
            'pc' => $pc->code,
        ];
    }
}
