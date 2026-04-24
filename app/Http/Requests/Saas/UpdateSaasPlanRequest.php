<?php

namespace App\Http\Requests\Saas;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateSaasPlanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:120'],
            'status' => ['sometimes', Rule::in(['active', 'archived'])],
            'price_per_pc_uzs' => ['sometimes', 'integer', 'min:0', 'max:1000000000'],
            'features' => ['sometimes', 'array'],
            'features.nexora_ai' => ['sometimes', 'boolean'],
            'features.ai_generation' => ['sometimes', 'boolean'],
            'features.ai_insights' => ['sometimes', 'boolean'],
            'features.ai_autopilot' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:1000000'],
        ];
    }

    public function payload(): array
    {
        return $this->validated();
    }
}
