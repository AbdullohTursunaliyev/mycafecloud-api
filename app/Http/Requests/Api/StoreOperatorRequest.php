<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreOperatorRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:64'],
            'login' => [
                'required',
                'string',
                'max:64',
                Rule::unique('operators', 'login')->where(fn($query) => $query->where('tenant_id', $this->tenantId())),
            ],
            'password' => ['required', 'string', 'min:4'],
            'role' => ['required', 'string', Rule::in(['operator', 'admin', 'owner'])],
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
