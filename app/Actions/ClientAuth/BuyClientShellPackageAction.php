<?php

namespace App\Actions\ClientAuth;

use App\Models\Client;
use App\Models\Pc;
use App\Services\ClientSessionService;
use App\Services\ClientShellContextService;

class BuyClientShellPackageAction
{
    public function __construct(
        private readonly ClientShellContextService $context,
        private readonly ClientSessionService $sessions,
    ) {
    }

    public function execute(string $licenseKey, string $pcCode, ?string $bearer, int $packageId): array
    {
        $license = $this->context->resolveLicenseForShell($licenseKey);
        $tenantId = (int) $license->tenant_id;
        $resolved = $this->context->resolveShellClient($bearer, $tenantId, true);
        $client = $resolved['client'];
        $pc = $this->context->resolvePcForShell($tenantId, $pcCode);

        $result = $this->sessions->purchasePackageFromClientBalance($tenantId, $client, $pc, $packageId);
        $updatedClient = $result['client'];

        return [
            'ok' => true,
            'client' => $this->clientPayload($updatedClient, $pc),
            'client_package_id' => (int) $result['client_package']->id,
            'package_id' => (int) $result['package']->id,
            'amount' => (int) $result['amount'],
            'billing_options' => $this->sessions->describeBillingOptions($tenantId, $updatedClient, $pc),
        ];
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
