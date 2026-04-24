<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ExecuteNexoraAssistantRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'plan_id' => ['required', 'string', 'max:80'],
            'confirmed' => ['required', 'boolean'],
            'locale' => ['nullable', 'string', Rule::in(['uz', 'ru', 'en'])],
        ];
    }

    public function planId(): string
    {
        return trim((string) $this->validated()['plan_id']);
    }

    public function confirmed(): bool
    {
        return (bool) $this->validated()['confirmed'];
    }

    public function localeCode(): string
    {
        $locale = $this->validated()['locale'] ?? 'uz';

        return is_string($locale) && $locale !== '' ? $locale : 'uz';
    }
}
