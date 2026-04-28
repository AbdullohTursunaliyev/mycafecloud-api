<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

class ClientShellBuyPackageRequest extends FormRequest
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
            'package_id' => ['required', 'integer'],
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

    public function packageId(): int
    {
        return (int) $this->validated('package_id');
    }
}
