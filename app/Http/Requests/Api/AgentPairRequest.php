<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

class AgentPairRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'pair_code' => ['required', 'string'],
            'pc_name' => ['nullable', 'string', 'max:64'],
            'ip' => ['nullable', 'ip'],
            'mac' => ['nullable', 'string', 'max:32'],
            'os' => ['nullable', 'string', 'max:64'],
        ];
    }

    public function payload(): array
    {
        return $this->validated();
    }
}
