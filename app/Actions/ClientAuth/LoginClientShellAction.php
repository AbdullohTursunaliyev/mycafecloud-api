<?php

namespace App\Actions\ClientAuth;

use App\Models\Client;
use App\Models\Pc;
use App\Services\ClientSessionService;
use App\Services\ClientShellContextService;
use App\Services\ClientTokenService;
use App\ValueObjects\ClientAuth\ClientShellLoginResult;

class LoginClientShellAction
{
    public function __construct(
        private readonly ClientShellContextService $context,
        private readonly ClientSessionService $sessions,
        private readonly ClientTokenService $tokens,
    ) {
    }

    public function execute(array $attributes): ClientShellLoginResult
    {
        $license = $this->context->resolveLicenseForLogin((string) $attributes['license_key']);
        $tenantId = (int) $license->tenant_id;

        $pc = $this->context->resolvePcForLogin($tenantId, (string) $attributes['pc_code']);
        $client = $this->context->resolveActiveClientByCredentials(
            $tenantId,
            $attributes['account_id'] ?? null,
            $attributes['login'] ?? null,
            $attributes['password'] ?? null,
        );

        $package = $this->sessions->ensureShellLoginAllowed($tenantId, $client, $pc);
        $session = $this->sessions->startOrResumeShellSession($tenantId, $pc, $client, $package);
        $issued = $this->tokens->issue($tenantId, (int) $client->id);

        return new ClientShellLoginResult(
            token: (string) $issued['plain'],
            client: $this->clientPayload($client, $pc),
            pc: $this->sessions->describePc($pc),
            session: $this->sessions->describeSession($session, $client, $pc),
            note: $session->wasRecentlyCreated ? null : 'existing_active_session',
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
