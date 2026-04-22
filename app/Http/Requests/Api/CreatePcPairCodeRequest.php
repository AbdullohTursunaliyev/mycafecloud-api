<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

class CreatePcPairCodeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'zone' => ['nullable', 'string', 'max:32'],
            'expires_in_min' => [
                'nullable',
                'integer',
                'min:' . (int) config('domain.pc.pair_code.min_ttl_minutes', 1),
                'max:' . (int) config('domain.pc.pair_code.max_ttl_minutes', 120),
            ],
        ];
    }

    public function zone(): ?string
    {
        $zone = $this->validated()['zone'] ?? null;

        return $zone === null ? null : trim((string) $zone);
    }

    public function expiresInMinutes(): int
    {
        return (int) ($this->validated()['expires_in_min'] ?? config('domain.pc.pair_code.default_ttl_minutes', 10));
    }
}
