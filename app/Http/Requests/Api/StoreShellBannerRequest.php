<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreShellBannerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        $tenantId = (int) $this->user()->tenant_id;

        return [
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'headline' => ['nullable', 'string', 'max:160'],
            'subheadline' => ['nullable', 'string', 'max:255'],
            'body_text' => ['nullable', 'string', 'max:4000'],
            'cta_text' => ['nullable', 'string', 'max:120'],
            'prompt_text' => ['nullable', 'string', 'max:8000'],
            'image_url' => ['nullable', 'string', 'max:2048'],
            'logo_url' => ['required', 'string', 'max:2048'],
            'audio_url' => ['nullable', 'string', 'max:2048'],
            'accent_color' => ['nullable', 'regex:/^#(?:[0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/'],
            'target_scope' => ['required', 'string', Rule::in(['all', 'zones', 'pcs'])],
            'target_zone_ids' => ['nullable', 'array'],
            'target_zone_ids.*' => [
                'integer',
                Rule::exists('zones', 'id')->where(fn ($query) => $query->where('tenant_id', $tenantId)),
            ],
            'target_pc_ids' => ['nullable', 'array'],
            'target_pc_ids.*' => [
                'integer',
                Rule::exists('pcs', 'id')->where(fn ($query) => $query->where('tenant_id', $tenantId)),
            ],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', 'after:starts_at'],
            'display_seconds' => ['nullable', 'integer', 'min:3', 'max:60'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:9999'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $scope = (string) $this->input('target_scope', 'all');
            if ($scope === 'zones' && count((array) $this->input('target_zone_ids', [])) === 0) {
                $validator->errors()->add('target_zone_ids', 'At least one zone is required for zone targeting.');
            }

            if ($scope === 'pcs' && count((array) $this->input('target_pc_ids', [])) === 0) {
                $validator->errors()->add('target_pc_ids', 'At least one PC is required for PC targeting.');
            }
        });
    }

    public function payload(): array
    {
        return $this->normalize($this->validated());
    }

    private function normalize(array $payload): array
    {
        foreach (['headline', 'subheadline', 'body_text', 'cta_text', 'prompt_text', 'image_url', 'logo_url', 'audio_url', 'accent_color'] as $key) {
            if (array_key_exists($key, $payload)) {
                $payload[$key] = $this->normalizeNullableString($payload[$key] ?? null);
            }
        }

        $payload['target_zone_ids'] = array_values(array_unique(array_map('intval', (array) ($payload['target_zone_ids'] ?? []))));
        $payload['target_pc_ids'] = array_values(array_unique(array_map('intval', (array) ($payload['target_pc_ids'] ?? []))));

        return $payload;
    }

    private function normalizeNullableString(mixed $value): ?string
    {
        $value = is_string($value) ? trim($value) : null;

        return $value !== '' ? $value : null;
    }
}
