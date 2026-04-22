<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AdminShellGamesIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'pc_id' => [
                'nullable',
                'integer',
                Rule::exists('pcs', 'id')->where(fn($query) => $query->where('tenant_id', $this->tenantId())),
            ],
        ];
    }

    public function pcId(): ?int
    {
        if (!array_key_exists('pc_id', $this->validated())) {
            return null;
        }

        return (int) $this->validated()['pc_id'];
    }

    private function tenantId(): int
    {
        return (int) ($this->user('operator')?->tenant_id ?? $this->user()?->tenant_id ?? 0);
    }
}
