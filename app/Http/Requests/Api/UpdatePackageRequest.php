<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

class UpdatePackageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'min:2', 'max:255'],
            'duration_min' => ['sometimes', 'required', 'integer', 'min:1', 'max:1000000'],
            'price' => ['sometimes', 'required', 'integer', 'min:0', 'max:2000000000'],
            'zone' => ['sometimes', 'required', 'string', 'max:255'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    public function payload(): array
    {
        return $this->validated();
    }
}
