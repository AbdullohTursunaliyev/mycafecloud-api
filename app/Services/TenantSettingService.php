<?php

namespace App\Services;

use App\Models\Setting;

class TenantSettingService
{
    public function get(int $tenantId, string $key, mixed $default = null): mixed
    {
        $value = Setting::query()
            ->where('tenant_id', $tenantId)
            ->where('key', $key)
            ->value('value');

        if ($value === null) {
            return $default;
        }

        if ($key === 'club_logo') {
            return $this->sanitizeLogoValue($value);
        }

        return $value;
    }

    public function set(int $tenantId, string $key, mixed $value): void
    {
        if ($key === 'club_logo') {
            $value = $this->sanitizeLogoValue($value);
        }

        if ($value === null) {
            $existing = Setting::query()
                ->where('tenant_id', $tenantId)
                ->where('key', $key)
                ->value('value');

            if (is_array($existing)) {
                $value = [];
            } elseif (is_bool($existing)) {
                $value = false;
            } elseif (is_int($existing) || is_float($existing)) {
                $value = 0;
            } else {
                $value = '';
            }
        }

        Setting::query()->updateOrCreate(
            ['tenant_id' => $tenantId, 'key' => $key],
            ['value' => $value]
        );
    }

    private function sanitizeLogoValue(mixed $value): string
    {
        if (!is_string($value)) {
            return '';
        }

        $value = trim($value);
        if ($value === '') {
            return '';
        }

        $decoded = rawurldecode($value);
        if (is_string($decoded) && $decoded !== '') {
            $value = $decoded;
        }

        $value = trim($value);
        $value = preg_replace('/^[\"\']+|[\"\']+$/', '', $value) ?? '';
        $value = trim($value);

        if ($value === '') {
            return '';
        }

        $lower = strtolower($value);
        if (
            str_starts_with($lower, 'data:image/') ||
            str_starts_with($lower, 'http://') ||
            str_starts_with($lower, 'https://') ||
            str_starts_with($value, '/')
        ) {
            return $value;
        }

        return '';
    }
}
