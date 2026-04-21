<?php

// app/Support/SettingService.php
namespace App\Service;

use App\Models\Setting;

class SettingService
{
    public static function get(int $tenantId, string $key, $default = null)
    {
        $value = Setting::where('tenant_id',$tenantId)
            ->where('key',$key)
            ->value('value');

        if ($value === null) {
            return $default;
        }

        if ($key === 'club_logo') {
            return self::sanitizeLogoValue($value);
        }

        return $value;
    }

    public static function set(int $tenantId, string $key, $value): void
    {
        if ($key === 'club_logo') {
            $value = self::sanitizeLogoValue($value);
        }

        if ($value === null) {
            $existing = Setting::where('tenant_id', $tenantId)
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

        Setting::updateOrCreate(
            ['tenant_id'=>$tenantId,'key'=>$key],
            ['value'=>$value]
        );
    }

    private static function sanitizeLogoValue(mixed $value): string
    {
        if (!is_string($value)) {
            return '';
        }

        $v = trim($value);
        if ($v === '') {
            return '';
        }

        $decoded = rawurldecode($v);
        if (is_string($decoded) && $decoded !== '') {
            $v = $decoded;
        }

        $v = trim($v);
        $v = preg_replace('/^[\"\']+|[\"\']+$/', '', $v) ?? '';
        $v = trim($v);

        if ($v === '') {
            return '';
        }

        $lower = strtolower($v);
        if (
            str_starts_with($lower, 'data:image/') ||
            str_starts_with($lower, 'http://') ||
            str_starts_with($lower, 'https://') ||
            str_starts_with($v, '/')
        ) {
            return $v;
        }

        return '';
    }
}
