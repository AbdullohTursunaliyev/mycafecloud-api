<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreClientRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        $tenantId = (int) $this->user()->tenant_id;

        return [
            'account_id' => ['nullable', 'string', 'max:64', Rule::unique('clients')->where(fn($query) => $query->where('tenant_id', $tenantId))],
            'login' => ['nullable', 'string', 'max:64', Rule::unique('clients')->where(fn($query) => $query->where('tenant_id', $tenantId))],
            'password' => ['nullable', 'string', 'min:4'],
            'phone' => ['nullable', 'string', 'max:32'],
            'username' => ['nullable', 'string', 'max:64'],
            'name' => ['nullable', 'string', 'max:64'],
        ];
    }

    public function payload(): array
    {
        return $this->validated();
    }
}
