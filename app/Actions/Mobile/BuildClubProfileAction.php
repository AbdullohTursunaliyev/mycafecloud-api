<?php

namespace App\Actions\Mobile;

use App\Services\MobileClubProfileService;

class BuildClubProfileAction
{
    public function __construct(
        private readonly MobileClubProfileService $profiles,
    ) {
    }

    public function execute(int $tenantId, int $clientId): array
    {
        return $this->profiles->build($tenantId, $clientId);
    }
}
