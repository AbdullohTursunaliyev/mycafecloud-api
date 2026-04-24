<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

class StoreClubVisualRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'headline' => ['nullable', 'string', 'max:160'],
            'subheadline' => ['nullable', 'string', 'max:255'],
            'description_text' => ['nullable', 'string', 'max:4000'],
            'prompt_text' => ['nullable', 'string', 'max:8000'],
            'display_mode' => ['required', 'string', 'in:upload,layout,hybrid'],
            'screen_mode' => ['required', 'string', 'in:card,poster,tv'],
            'accent_color' => ['nullable', 'regex:/^#(?:[0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/'],
            'image_url' => ['nullable', 'string', 'max:2048'],
            'audio_url' => ['nullable', 'string', 'max:2048'],
            'layout_spec' => ['nullable', 'array'],
            'visual_spec' => ['nullable', 'array'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:9999'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }

    public function payload(): array
    {
        return $this->normalize($this->validated());
    }

    private function normalize(array $payload): array
    {
        foreach (['headline', 'subheadline', 'description_text', 'prompt_text', 'accent_color', 'image_url', 'audio_url'] as $key) {
            if (array_key_exists($key, $payload)) {
                $payload[$key] = $this->normalizeNullableString($payload[$key] ?? null);
            }
        }

        return $payload;
    }

    private function normalizeNullableString(mixed $value): ?string
    {
        $value = is_string($value) ? trim($value) : null;

        return $value !== '' ? $value : null;
    }
}
