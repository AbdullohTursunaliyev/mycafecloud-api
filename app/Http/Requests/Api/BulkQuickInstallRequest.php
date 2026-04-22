<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

class BulkQuickInstallRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'count' => ['required', 'integer', 'min:1', 'max:300'],
            'zone_id' => ['nullable', 'integer'],
            'zone' => ['nullable', 'string', 'max:32'],
            'expires_in_min' => [
                'nullable',
                'integer',
                'min:1',
                'max:' . (int) config('domain.pc.pair_code.max_ttl_minutes', 120),
            ],
        ];
    }

    public function countValue(): int
    {
        return (int) $this->validated()['count'];
    }

    public function zoneId(): ?int
    {
        $value = $this->validated()['zone_id'] ?? null;

        return $value === null ? null : (int) $value;
    }

    public function zoneName(): ?string
    {
        $value = $this->validated()['zone'] ?? null;
        if (!is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }

    public function expiresInMin(): ?int
    {
        $value = $this->validated()['expires_in_min'] ?? null;

        return $value === null ? null : (int) $value;
    }
}
