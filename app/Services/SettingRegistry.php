<?php

namespace App\Services;

class SettingRegistry
{
    public function allowedKeys(): array
    {
        return config('settings.allowed_keys', []);
    }

    public function defaults(): array
    {
        return config('settings.defaults', []);
    }

    public function defaultValue(string $key, mixed $fallback = null): mixed
    {
        return config('settings.defaults.' . $key, $fallback);
    }

    public function normalizeForResponse(string $key, mixed $value, string $baseUrl): mixed
    {
        return match ($key) {
            'promo_video_url',
            'deploy_agent_download_url',
            'deploy_client_download_url',
            'deploy_shell_download_url' => $this->normalizePublicUrl($value, $baseUrl),
            default => $value,
        };
    }

    public function normalizeForStorage(string $key, mixed $value, string $baseUrl): mixed
    {
        return match ($key) {
            'promo_video_url',
            'deploy_agent_download_url',
            'deploy_client_download_url',
            'deploy_shell_download_url' => $this->normalizePublicUrl($value, $baseUrl),
            default => $value,
        };
    }

    public function applyBusinessRules(int $tenantId, array $settings, TenantSettingService $tenantSettings): array
    {
        $currentAutoShiftEnabled = $this->asBool($tenantSettings->get($tenantId, 'auto_shift_enabled', false));
        $nextAutoShiftEnabled = array_key_exists('auto_shift_enabled', $settings)
            ? $this->asBool($settings['auto_shift_enabled'])
            : $currentAutoShiftEnabled;

        $currentSlots = $tenantSettings->get($tenantId, 'auto_shift_slots', []);
        if (!is_array($currentSlots)) {
            $currentSlots = [];
        }

        $incomingSlots = [];
        if (array_key_exists('auto_shift_slots', $settings) && is_array($settings['auto_shift_slots'])) {
            $incomingSlots = $settings['auto_shift_slots'];
        }

        if ($nextAutoShiftEnabled) {
            if (array_key_exists('auto_shift_slots', $settings) && count($incomingSlots) === 0 && count($currentSlots) > 0) {
                unset($settings['auto_shift_slots']);
            }

            $effectiveSlots = array_key_exists('auto_shift_slots', $settings)
                ? (is_array($settings['auto_shift_slots']) ? $settings['auto_shift_slots'] : [])
                : $currentSlots;

            if (count($effectiveSlots) < 1) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'settings.auto_shift_slots' => 'The auto shift slots field must have at least 1 items.',
                ]);
            }
        }

        return $settings;
    }

    public function normalizePublicUrl(mixed $value, string $baseUrl): mixed
    {
        if (!is_string($value)) {
            return $value;
        }

        $url = trim($value);
        if ($url === '') {
            return $value;
        }

        if (str_starts_with($url, '/')) {
            return $baseUrl . $url;
        }

        $fixed = preg_replace('#^https?://(localhost|127\\.0\\.0\\.1)(:\\d+)?#i', $baseUrl, $url);

        return $fixed ?: $url;
    }

    public function asBool(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        $string = strtolower(trim((string) $value));

        return !in_array($string, ['', '0', 'false', 'off', 'no', 'null'], true);
    }
}
