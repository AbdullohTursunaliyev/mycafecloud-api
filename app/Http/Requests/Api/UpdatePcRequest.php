<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdatePcRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        $tenantId = (int) $this->user()->tenant_id;
        $pcId = (int) $this->route('id');

        return [
            'code' => ['sometimes', 'string', 'max:32', Rule::unique('pcs')->ignore($pcId)->where(fn($query) => $query->where('tenant_id', $tenantId))],
            'zone_id' => ['sometimes', 'nullable', 'integer', Rule::exists('zones', 'id')->where(fn($query) => $query->where('tenant_id', $tenantId))],
            'zone' => ['sometimes', 'nullable', 'string', 'max:120'],
            'ip_address' => ['sometimes', 'nullable', 'ip'],
            'status' => ['sometimes', 'string', 'in:offline,online,busy,reserved,maintenance,locked'],
            'pos_x' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:10000'],
            'pos_y' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:10000'],
            'group' => ['sometimes', 'nullable', 'string', 'max:50'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:100000'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:255'],
            'is_hidden' => ['sometimes', 'boolean'],
        ];
    }

    public function payload(): array
    {
        return $this->validated();
    }
}
