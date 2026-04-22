<?php

namespace App\Actions\Settings;

use App\Services\SettingRegistry;
use App\Services\TenantSettingService;

class UpdateTenantSettingsAction
{
    public function __construct(
        private readonly TenantSettingService $settings,
        private readonly SettingRegistry $registry,
    ) {
    }

    public function execute(int $tenantId, array $values, string $baseUrl): void
    {
        $settings = $this->registry->applyBusinessRules($tenantId, $values, $this->settings);

        foreach ($settings as $key => $value) {
            $this->settings->set(
                $tenantId,
                $key,
                $this->registry->normalizeForStorage($key, $value, $baseUrl),
            );
        }
    }
}
