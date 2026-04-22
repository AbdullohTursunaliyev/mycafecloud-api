<?php

namespace App\Actions\ClientAuth;

use App\Services\ClientSessionService;
use App\Services\ClientShellContextService;
use App\Services\ClientTokenService;

class LogoutClientShellAction
{
    public function __construct(
        private readonly ClientShellContextService $context,
        private readonly ClientSessionService $sessions,
        private readonly ClientTokenService $tokens,
    ) {
    }

    public function execute(string $licenseKey, string $pcCode, ?string $bearer): void
    {
        $license = $this->context->resolveLicenseForShell($licenseKey);
        $tenantId = (int) $license->tenant_id;
        $resolved = $this->context->resolveShellClient($bearer, $tenantId, false);
        $client = $resolved['client'];
        $token = $resolved['token'];
        $pc = $this->context->findPc($tenantId, $pcCode, true);

        if ($pc) {
            $this->sessions->logoutClientFromPc($tenantId, $pc, $client);
        }

        $this->tokens->revoke($token);
    }
}
