<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateNexoraAutopilotRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'enabled' => ['required', 'boolean'],
            'auto_lock_idle_online' => ['required', 'boolean'],
            'suggest_idle_shutdown' => ['required', 'boolean'],
            'suggest_offline_watch' => ['required', 'boolean'],
            'locale' => ['nullable', 'string', Rule::in(['uz', 'ru', 'en'])],
        ];
    }

    public function settings(): array
    {
        $validated = $this->validated();

        return [
            'enabled' => (bool) ($validated['enabled'] ?? false),
            'auto_lock_idle_online' => (bool) ($validated['auto_lock_idle_online'] ?? false),
            'suggest_idle_shutdown' => (bool) ($validated['suggest_idle_shutdown'] ?? true),
            'suggest_offline_watch' => (bool) ($validated['suggest_offline_watch'] ?? true),
        ];
    }

    public function localeCode(): string
    {
        $locale = $this->validated()['locale'] ?? 'uz';

        return is_string($locale) && $locale !== '' ? $locale : 'uz';
    }
}
