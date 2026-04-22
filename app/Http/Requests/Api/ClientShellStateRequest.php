<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

class ClientShellStateRequest extends FormRequest
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
        ];
    }

    public function licenseKey(): string
    {
        return (string) $this->validated()['license_key'];
    }

    public function pcCode(): string
    {
        return (string) $this->validated()['pc_code'];
    }
}
