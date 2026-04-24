<?php

namespace App\Http\Requests\Saas;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreTenantRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'status' => ['nullable', Rule::in(['active', 'suspended'])],
            'saas_plan_id' => ['nullable', 'integer', 'exists:saas_plans,id'],
        ];
    }

    public function payload(): array
    {
        return $this->validated();
    }
}
