<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateOperatorRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        $operatorId = (int) $this->route('id');

        return [
            'name' => ['sometimes', 'string', 'max:64'],
            'login' => [
                'sometimes',
                'string',
                'max:64',
                Rule::unique('operators', 'login')
                    ->ignore($operatorId)
                    ->where(fn($query) => $query->where('tenant_id', $this->tenantId())),
            ],
            'password' => ['sometimes', 'string', 'min:4'],
            'role' => ['sometimes', 'string', Rule::in(['operator', 'admin', 'owner'])],
            'is_active' => ['sometimes', 'boolean'],
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
