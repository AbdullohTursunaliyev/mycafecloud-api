<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

class QuickInstallRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'pc_id' => ['nullable', 'integer'],
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

    public function pcId(): ?int
    {
        return $this->validatedInteger('pc_id');
    }

    public function zoneId(): ?int
    {
        return $this->validatedInteger('zone_id');
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
        return $this->validatedInteger('expires_in_min');
    }

    private function validatedInteger(string $key): ?int
    {
        $value = $this->validated()[$key] ?? null;

        return $value === null ? null : (int) $value;
    }
}
