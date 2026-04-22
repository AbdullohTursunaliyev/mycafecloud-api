<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SubscriptionPlanIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'q' => ['nullable', 'string', 'max:255'],
            'active' => ['nullable', 'boolean'],
            'zone_id' => [
                'nullable',
                'integer',
                Rule::exists('zones', 'id')->where(fn($query) => $query->where('tenant_id', $this->tenantId())),
            ],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:200'],
        ];
    }

    public function filters(): array
    {
        $validated = $this->validated();

        return [
            'q' => trim((string) ($validated['q'] ?? '')),
            'active' => array_key_exists('active', $validated) ? (bool) $validated['active'] : null,
            'zone_id' => array_key_exists('zone_id', $validated) ? (int) $validated['zone_id'] : null,
        ];
    }

    public function perPage(): int
    {
        return (int) ($this->validated()['per_page'] ?? 20);
    }

    private function tenantId(): int
    {
        return (int) ($this->user('operator')?->tenant_id ?? $this->user()?->tenant_id ?? 0);
    }
}
