<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class BatchUpdateLayoutCellsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'items' => ['required', 'array', 'max:500'],
            'items.*.id' => ['nullable', 'integer'],
            'items.*.row' => ['required', 'integer', 'min:1', 'max:500'],
            'items.*.col' => ['required', 'integer', 'min:1', 'max:500'],
            'items.*.zone_id' => [
                'nullable',
                'integer',
                Rule::exists('zones', 'id')->where(fn($query) => $query->where('tenant_id', $this->tenantId())),
            ],
            'items.*.pc_id' => [
                'nullable',
                'integer',
                Rule::exists('pcs', 'id')->where(fn($query) => $query->where('tenant_id', $this->tenantId())),
            ],
            'items.*.label' => ['nullable', 'string', 'max:40'],
            'items.*.is_active' => ['nullable', 'boolean'],
            'items.*.notes' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function items(): array
    {
        $validated = $this->validated();

        return is_array($validated['items'] ?? null) ? $validated['items'] : [];
    }

    private function tenantId(): int
    {
        return (int) ($this->user('operator')?->tenant_id ?? $this->user()?->tenant_id ?? 0);
    }
}
