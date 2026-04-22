<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

class ShellGamesIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'pc_code' => ['nullable', 'string', 'max:64'],
        ];
    }

    public function pcCode(): string
    {
        return trim((string) ($this->validated()['pc_code'] ?? ''));
    }
}
