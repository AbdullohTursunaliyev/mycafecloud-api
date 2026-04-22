<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PcLayoutBatchUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        $tenantId = (int) $this->user()->tenant_id;

        return [
            'items' => ['required', 'array', 'max:500'],
            'items.*.id' => ['required', 'integer'],
            'items.*.pos_x' => ['nullable', 'integer', 'min:0', 'max:10000'],
            'items.*.pos_y' => ['nullable', 'integer', 'min:0', 'max:10000'],
            'items.*.sort_order' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'items.*.zone_id' => ['nullable', 'integer', Rule::exists('zones', 'id')->where(fn($query) => $query->where('tenant_id', $tenantId))],
            'items.*.zone' => ['nullable', 'string', 'max:120'],
            'items.*.group' => ['nullable', 'string', 'max:50'],
        ];
    }

    public function items(): array
    {
        return (array) ($this->validated()['items'] ?? []);
    }
}
