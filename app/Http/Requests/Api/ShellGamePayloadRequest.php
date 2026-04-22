<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

abstract class ShellGamePayloadRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        $required = $this->isPartial() ? 'sometimes' : 'required';
        $ignoreId = $this->route('id');

        return [
            'slug' => [
                $required,
                'string',
                'max:64',
                Rule::unique('shell_games', 'slug')
                    ->where(fn($query) => $query->where('tenant_id', $this->tenantId()))
                    ->ignore($ignoreId),
            ],
            'title' => [$required, 'string', 'max:120'],
            'category' => ['nullable', 'string', 'max:40'],
            'age_rating' => ['nullable', 'string', 'max:10'],
            'badge' => ['nullable', 'string', 'max:60'],
            'note' => ['nullable', 'string', 'max:200'],
            'launcher' => ['nullable', 'string', 'max:40'],
            'launcher_icon' => ['nullable', 'string', 'max:8'],
            'image_url' => ['nullable', 'string', 'max:2000'],
            'hero_url' => ['nullable', 'string', 'max:2000'],
            'trailer_url' => ['nullable', 'string', 'max:2000'],
            'website_url' => ['nullable', 'string', 'max:2000'],
            'help_text' => ['nullable', 'string', 'max:1000'],
            'launch_command' => ['nullable', 'string', 'max:1000'],
            'launch_args' => ['nullable', 'string', 'max:1000'],
            'working_dir' => ['nullable', 'string', 'max:1000'],
            'cloud_profile_enabled' => ['sometimes', 'boolean'],
            'config_paths' => ['nullable', 'array', 'max:30'],
            'config_paths.*' => ['string', 'max:500'],
            'mouse_hints' => ['nullable', 'array'],
            'is_active' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:100000'],
        ];
    }

    public function payload(): array
    {
        return $this->validated();
    }

    abstract protected function isPartial(): bool;

    private function tenantId(): int
    {
        return (int) ($this->user('operator')?->tenant_id ?? $this->user()?->tenant_id ?? 0);
    }
}
