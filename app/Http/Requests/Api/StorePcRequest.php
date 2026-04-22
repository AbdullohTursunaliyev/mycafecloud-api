<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePcRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        $tenantId = (int) $this->user()->tenant_id;

        return [
            'code' => ['required', 'string', 'max:32', Rule::unique('pcs')->where(fn($query) => $query->where('tenant_id', $tenantId))],
            'zone_id' => ['nullable', 'integer', Rule::exists('zones', 'id')->where(fn($query) => $query->where('tenant_id', $tenantId))],
            'zone' => ['nullable', 'string', 'max:120'],
            'ip_address' => ['nullable', 'ip'],
            'status' => ['nullable', 'string', 'in:offline,online,busy,reserved,maintenance,locked'],
            'pos_x' => ['nullable', 'integer', 'min:0', 'max:10000'],
            'pos_y' => ['nullable', 'integer', 'min:0', 'max:10000'],
            'group' => ['nullable', 'string', 'max:50'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'notes' => ['nullable', 'string', 'max:255'],
            'is_hidden' => ['nullable', 'boolean'],
        ];
    }

    public function payload(): array
    {
        return $this->validated();
    }
}
