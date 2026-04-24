<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateShellBannerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        $tenantId = (int) $this->user()->tenant_id;

        return [
            'name' => ['sometimes', 'required', 'string', 'min:2', 'max:120'],
            'headline' => ['sometimes', 'nullable', 'string', 'max:160'],
            'subheadline' => ['sometimes', 'nullable', 'string', 'max:255'],
            'body_text' => ['sometimes', 'nullable', 'string', 'max:4000'],
            'cta_text' => ['sometimes', 'nullable', 'string', 'max:120'],
            'prompt_text' => ['sometimes', 'nullable', 'string', 'max:8000'],
            'image_url' => ['sometimes', 'nullable', 'string', 'max:2048'],
            'logo_url' => ['sometimes', 'required', 'string', 'max:2048'],
            'audio_url' => ['sometimes', 'nullable', 'string', 'max:2048'],
            'accent_color' => ['sometimes', 'nullable', 'regex:/^#(?:[0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/'],
            'target_scope' => ['sometimes', 'required', 'string', Rule::in(['all', 'zones', 'pcs'])],
            'target_zone_ids' => ['sometimes', 'nullable', 'array'],
            'target_zone_ids.*' => [
                'integer',
                Rule::exists('zones', 'id')->where(fn ($query) => $query->where('tenant_id', $tenantId)),
            ],
            'target_pc_ids' => ['sometimes', 'nullable', 'array'],
            'target_pc_ids.*' => [
                'integer',
                Rule::exists('pcs', 'id')->where(fn ($query) => $query->where('tenant_id', $tenantId)),
            ],
            'starts_at' => ['sometimes', 'nullable', 'date'],
            'ends_at' => ['sometimes', 'nullable', 'date', 'after:starts_at'],
            'display_seconds' => ['sometimes', 'integer', 'min:3', 'max:60'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:9999'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $scope = (string) $this->input('target_scope', '');

            if ($scope === 'zones' && $this->has('target_zone_ids') && count((array) $this->input('target_zone_ids', [])) === 0) {
                $validator->errors()->add('target_zone_ids', 'At least one zone is required for zone targeting.');
            }

            if ($scope === 'pcs' && $this->has('target_pc_ids') && count((array) $this->input('target_pc_ids', [])) === 0) {
                $validator->errors()->add('target_pc_ids', 'At least one PC is required for PC targeting.');
            }
        });
    }

    public function payload(): array
    {
        $payload = $this->validated();

        foreach (['headline', 'subheadline', 'body_text', 'cta_text', 'prompt_text', 'image_url', 'logo_url', 'audio_url', 'accent_color'] as $key) {
            if (array_key_exists($key, $payload)) {
                $payload[$key] = $this->normalizeNullableString($payload[$key] ?? null);
            }
        }

        if (array_key_exists('target_zone_ids', $payload)) {
            $payload['target_zone_ids'] = array_values(array_unique(array_map('intval', (array) $payload['target_zone_ids'])));
        }

        if (array_key_exists('target_pc_ids', $payload)) {
            $payload['target_pc_ids'] = array_values(array_unique(array_map('intval', (array) $payload['target_pc_ids'])));
        }

        return $payload;
    }

    private function normalizeNullableString(mixed $value): ?string
    {
        $value = is_string($value) ? trim($value) : null;

        return $value !== '' ? $value : null;
    }
}
