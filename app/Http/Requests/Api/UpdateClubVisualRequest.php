<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

class UpdateClubVisualRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'min:2', 'max:120'],
            'headline' => ['sometimes', 'nullable', 'string', 'max:160'],
            'subheadline' => ['sometimes', 'nullable', 'string', 'max:255'],
            'description_text' => ['sometimes', 'nullable', 'string', 'max:4000'],
            'prompt_text' => ['sometimes', 'nullable', 'string', 'max:8000'],
            'display_mode' => ['sometimes', 'required', 'string', 'in:upload,layout,hybrid'],
            'screen_mode' => ['sometimes', 'required', 'string', 'in:card,poster,tv'],
            'accent_color' => ['sometimes', 'nullable', 'regex:/^#(?:[0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/'],
            'image_url' => ['sometimes', 'nullable', 'string', 'max:2048'],
            'audio_url' => ['sometimes', 'nullable', 'string', 'max:2048'],
            'layout_spec' => ['sometimes', 'nullable', 'array'],
            'visual_spec' => ['sometimes', 'nullable', 'array'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:9999'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    public function payload(): array
    {
        $payload = $this->validated();

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
