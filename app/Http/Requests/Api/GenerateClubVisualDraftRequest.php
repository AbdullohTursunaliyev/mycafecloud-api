<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

class GenerateClubVisualDraftRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'prompt_text' => ['nullable', 'string', 'max:8000', 'required_without:audio_url'],
            'audio_url' => ['nullable', 'string', 'max:2048', 'required_without:prompt_text'],
            'display_mode' => ['required', 'string', 'in:upload,layout,hybrid'],
            'screen_mode' => ['required', 'string', 'in:card,poster,tv'],
            'accent_color' => ['nullable', 'regex:/^#(?:[0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/'],
            'layout_spec' => ['nullable', 'array'],
        ];
    }

    public function payload(): array
    {
        $payload = $this->validated();
        $payload['prompt_text'] = $this->normalizeNullableString($payload['prompt_text'] ?? null);
        $payload['audio_url'] = $this->normalizeNullableString($payload['audio_url'] ?? null);
        $payload['accent_color'] = $this->normalizeNullableString($payload['accent_color'] ?? null);

        return $payload;
    }

    private function normalizeNullableString(mixed $value): ?string
    {
        $value = is_string($value) ? trim($value) : null;

        return $value !== '' ? $value : null;
    }
}
