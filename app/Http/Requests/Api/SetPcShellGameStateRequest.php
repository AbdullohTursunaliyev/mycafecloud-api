<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

class SetPcShellGameStateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'is_installed' => ['required', 'boolean'],
            'version' => ['nullable', 'string', 'max:64'],
            'last_error' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function payload(): array
    {
        return $this->validated();
    }
}
