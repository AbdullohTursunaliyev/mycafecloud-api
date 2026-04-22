<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

class PcIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'zone_id' => ['nullable', 'integer'],
            'zone' => ['nullable', 'string', 'max:120'],
            'status' => ['nullable', 'string', 'max:40'],
            'search' => ['nullable', 'string', 'max:120'],
        ];
    }

    public function filters(): array
    {
        $validated = $this->validated();

        return [
            'zone_id' => isset($validated['zone_id']) ? (int) $validated['zone_id'] : null,
            'zone' => $validated['zone'] ?? null,
            'status' => $validated['status'] ?? null,
            'search' => $validated['search'] ?? null,
        ];
    }
}
