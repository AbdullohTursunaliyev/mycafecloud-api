<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateSubscriptionPlanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'min:3', 'max:120'],
            'zone_id' => [
                'sometimes',
                'required',
                'integer',
                Rule::exists('zones', 'id')->where(fn($query) => $query->where('tenant_id', $this->tenantId())),
            ],
            'duration_days' => ['sometimes', 'required', 'integer', 'min:1', 'max:3650'],
            'price' => ['sometimes', 'required', 'integer', 'min:0'],
            'is_active' => ['sometimes', 'required', 'boolean'],
        ];
    }

    public function payload(): array
    {
        return $this->validated();
    }

    private function tenantId(): int
    {
        return (int) ($this->user('operator')?->tenant_id ?? $this->user()?->tenant_id ?? 0);
    }
}
