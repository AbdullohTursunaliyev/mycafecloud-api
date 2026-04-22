<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

class ZoneIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'active' => ['nullable', 'boolean'],
        ];
    }

    public function filters(): array
    {
        $validated = $this->validated();

        return [
            'active' => array_key_exists('active', $validated) ? (bool) $validated['active'] : null,
        ];
    }
}
