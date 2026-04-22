<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

class OwnerMobileLoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'license_key' => ['required', 'string'],
            'login' => ['required', 'string'],
            'password' => ['required', 'string'],
        ];
    }

    public function licenseKey(): string
    {
        return (string) $this->validated()['license_key'];
    }

    public function loginValue(): string
    {
        return (string) $this->validated()['login'];
    }

    public function passwordValue(): string
    {
        return (string) $this->validated()['password'];
    }
}
