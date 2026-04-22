<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class UpdateSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        $rules = [
            'settings' => ['required', 'array'],
        ];

        foreach (config('settings.update_rules', []) as $key => $ruleSet) {
            $rules['settings.' . $key] = $ruleSet;
        }

        return $rules;
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $settings = $this->input('settings', []);
            if (!is_array($settings)) {
                return;
            }

            $allowed = config('settings.allowed_keys', []);
            $unknown = array_values(array_diff(array_keys($settings), $allowed));

            foreach ($unknown as $key) {
                $validator->errors()->add('settings.' . $key, 'Unsupported setting key.');
            }
        });
    }

    public function settings(): array
    {
        $validated = $this->validated();

        return is_array($validated['settings'] ?? null) ? $validated['settings'] : [];
    }
}
