<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SpeakNexoraAssistantRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'text' => ['required', 'string', 'max:1200'],
            'locale' => ['nullable', 'string', Rule::in(['uz', 'ru', 'en'])],
        ];
    }

    public function text(): string
    {
        return trim((string) $this->validated()['text']);
    }

    public function localeCode(): string
    {
        $locale = $this->validated()['locale'] ?? 'uz';

        return is_string($locale) && $locale !== '' ? $locale : 'uz';
    }
}
