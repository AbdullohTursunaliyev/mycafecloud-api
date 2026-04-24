<?php

namespace App\Http\Requests\Landing;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreLandingLeadRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'club' => ['required', 'string', 'max:160'],
            'city' => ['nullable', 'string', 'max:120'],
            'pcs' => ['required', 'integer', 'min:1', 'max:10000'],
            'plan' => ['nullable', Rule::in(['basic', 'pro'])],
            'contact' => ['required', 'string', 'max:160'],
            'message' => ['nullable', 'string', 'max:2000'],
            'locale' => ['nullable', 'string', 'max:10'],
            'source' => ['nullable', 'string', 'max:80'],
            'created_at' => ['nullable', 'date'],
        ];
    }

    public function payload(): array
    {
        $validated = $this->validated();

        return [
            'source' => $validated['source'] ?? 'nexora-landing',
            'club_name' => $validated['club'],
            'city' => $validated['city'] ?? null,
            'pc_count' => (int) $validated['pcs'],
            'plan_code' => $validated['plan'] ?? null,
            'contact' => $validated['contact'],
            'message' => $validated['message'] ?? null,
            'locale' => $validated['locale'] ?? null,
            'meta' => [
                'client_created_at' => $validated['created_at'] ?? null,
            ],
        ];
    }
}
