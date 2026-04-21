<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;

class Setting extends Model
{
    protected $fillable = ['tenant_id', 'key', 'value'];

    protected function value(): Attribute
    {
        return Attribute::make(
            get: function ($value, array $attributes) {
                if ($value === null) {
                    return null;
                }

                if (!is_string($value)) {
                    return $value;
                }

                $key = (string)($attributes['key'] ?? '');
                $trimmed = trim($value);
                if ($trimmed === '') {
                    return '';
                }

                $decoded = json_decode($trimmed, true);
                if (json_last_error() === JSON_ERROR_NONE) {
                    $value = $decoded;
                } else {
                    $value = $trimmed;
                }

                if (in_array($key, ['telegram_shift_notifications', 'auto_shift_enabled'], true)) {
                    if (is_bool($value)) {
                        return $value;
                    }
                    $boolText = strtolower(trim((string)$value));
                    return !in_array($boolText, ['0', 'false', 'off', 'no', ''], true);
                }

                if (in_array($key, ['auto_shift_opening_cash'], true) && is_numeric((string)$value)) {
                    return (int)$value;
                }

                if ($key === 'club_logo') {
                    $logo = trim((string)$value);
                    $decodedLogo = rawurldecode($logo);
                    if (is_string($decodedLogo) && $decodedLogo !== '') {
                        $logo = trim($decodedLogo);
                    }

                    $logo = preg_replace('/^[\"\']+|[\"\']+$/', '', $logo) ?? '';
                    $logo = trim($logo);
                    if ($logo === '') {
                        return '';
                    }

                    $lower = strtolower($logo);
                    if (
                        str_starts_with($lower, 'data:image/') ||
                        str_starts_with($lower, 'http://') ||
                        str_starts_with($lower, 'https://') ||
                        str_starts_with($logo, '/')
                    ) {
                        return $logo;
                    }

                    return '';
                }

                return $value;
            },
            set: function ($value) {
                if ($value === null) {
                    $value = '';
                }

                if (is_array($value) || is_object($value)) {
                    return json_encode($value, JSON_UNESCAPED_UNICODE);
                }

                if (is_bool($value) || is_int($value) || is_float($value) || is_string($value)) {
                    return json_encode($value, JSON_UNESCAPED_UNICODE);
                }

                return json_encode((string)$value, JSON_UNESCAPED_UNICODE);
            }
        );
    }
}
