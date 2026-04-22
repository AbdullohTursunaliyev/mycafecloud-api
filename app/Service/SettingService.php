<?php

namespace App\Service;

use App\Services\TenantSettingService;

class SettingService
{
    public static function get(int $tenantId, string $key, mixed $default = null): mixed
    {
        return app(TenantSettingService::class)->get($tenantId, $key, $default);
    }

    public static function set(int $tenantId, string $key, mixed $value): void
    {
        app(TenantSettingService::class)->set($tenantId, $key, $value);
    }
}
