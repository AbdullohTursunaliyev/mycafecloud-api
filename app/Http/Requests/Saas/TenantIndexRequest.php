<?php

namespace App\Http\Requests\Saas;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class TenantIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'status' => ['nullable', Rule::in(['active', 'suspended'])],
            'search' => ['nullable', 'string', 'max:120'],
            'plan_code' => ['nullable', Rule::in(['basic', 'pro'])],
        ];
    }

    public function filters(): array
    {
        $validated = $this->validated();

        return [
            'status' => $validated['status'] ?? null,
            'search' => trim((string) ($validated['search'] ?? '')),
            'plan_code' => $validated['plan_code'] ?? null,
        ];
    }
}
