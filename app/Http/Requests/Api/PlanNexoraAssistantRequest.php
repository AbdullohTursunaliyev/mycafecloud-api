<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PlanNexoraAssistantRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'message' => ['required', 'string', 'max:1000'],
            'locale' => ['nullable', 'string', Rule::in(['uz', 'ru', 'en'])],
        ];
    }

    public function message(): string
    {
        return trim((string) $this->validated()['message']);
    }

    public function localeCode(): string
    {
        $locale = $this->validated()['locale'] ?? 'uz';

        return is_string($locale) && $locale !== '' ? $locale : 'uz';
    }
}
