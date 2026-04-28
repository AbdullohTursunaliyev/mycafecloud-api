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
        $billingOptions = $this->sessions->describeBillingOptions($tenantId, $client, $pc);
        $issued = $this->tokens->issue($tenantId, (int) $client->id);

        if ((bool) ($attributes['defer_session'] ?? false)) {
            $existing = $this->sessions->resolveOwnedActiveSession($tenantId, $pc, $client);

            return new ClientShellLoginResult(
                token: (string) $issued['plain'],
                client: $this->clientPayload($client, $pc),
                pc: $this->sessions->describePc($pc),
                session: $existing
                    ? $this->sessions->describeSession($existing, $client, $pc)
                    : $this->pendingSessionPayload($pc),
                note: $existing ? 'existing_active_session' : 'session_deferred',
                billingOptions: $billingOptions,
            );
        }

        $session = $this->sessions->startOrResumeShellSession($tenantId, $pc, $client, $package);

        return new ClientShellLoginResult(
            token: (string) $issued['plain'],
            client: $this->clientPayload($client, $pc),
            pc: $this->sessions->describePc($pc),
            session: $this->sessions->describeSession($session, $client, $pc),
            note: $session->wasRecentlyCreated ? null : 'existing_active_session',
            billingOptions: $billingOptions,
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

    private function pendingSessionPayload(Pc $pc): array
    {
        $pcView = $this->sessions->describePc($pc);

        return [
            'id' => null,
            'status' => 'pending',
            'pc_id' => (int) $pc->id,
            'pc_code' => $pc->code,
            'started_at' => null,
            'is_package' => false,
            'client_package_id' => null,
            'left_sec' => 0,
            'seconds_left' => 0,
            'from' => 'pending',
            'zone' => $pcView['zone'],
            'rate_per_hour' => $pcView['rate_per_hour'],
            'next_charge_at' => null,
            'paused' => true,
            'pricing_rule' => null,
        ];
    }
}
