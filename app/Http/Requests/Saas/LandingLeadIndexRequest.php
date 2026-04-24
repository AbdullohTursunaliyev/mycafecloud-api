<?php

namespace App\Http\Requests\Saas;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class LandingLeadIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'status' => ['nullable', Rule::in(['new', 'contacted', 'converted', 'archived'])],
            'plan_code' => ['nullable', Rule::in(['basic', 'pro'])],
            'search' => ['nullable', 'string', 'max:160'],
        ];
    }

    public function filters(): array
    {
        $validated = $this->validated();

        return [
            'status' => $validated['status'] ?? null,
            'plan_code' => $validated['plan_code'] ?? null,
            'search' => trim((string) ($validated['search'] ?? '')),
        ];
    }
}
