<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

class StartAgentSessionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'client_id' => ['required', 'integer'],
            'tariff_id' => ['required', 'integer'],
        ];
    }

    public function clientId(): int
    {
        return (int) $this->validated()['client_id'];
    }

    public function tariffId(): int
    {
        return (int) $this->validated()['tariff_id'];
    }
}
