<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ClientShellStartSessionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'license_key' => ['required', 'string'],
            'pc_code' => ['required', 'string'],
            'source' => ['required', 'string', Rule::in(['balance', 'package'])],
            'client_package_id' => ['nullable', 'integer'],
        ];
    }

    public function licenseKey(): string
    {
        return (string) $this->validated('license_key');
    }

    public function pcCode(): string
    {
        return (string) $this->validated('pc_code');
    }

    public function source(): string
    {
        return (string) $this->validated('source');
    }

    public function clientPackageId(): ?int
    {
        $data = $this->validated();
        $value = $data['client_package_id'] ?? null;
        return $value === null ? null : (int) $value;
    }
}
