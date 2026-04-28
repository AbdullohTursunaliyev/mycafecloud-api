<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

class ClientShellLoginRequest extends FormRequest
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
            'account_id' => ['nullable', 'string'],
            'login' => ['nullable', 'string'],
            'password' => ['nullable', 'string'],
            'defer_session' => ['nullable', 'boolean'],
        ];
    }

    public function payload(): array
    {
        return $this->validated();
    }
}
