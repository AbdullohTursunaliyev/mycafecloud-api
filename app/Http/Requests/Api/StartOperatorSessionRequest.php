<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

class StartOperatorSessionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'pc_id' => ['required', 'integer'],
            'tariff_id' => ['required', 'integer'],
            'client_id' => ['required', 'integer'],
        ];
    }

    public function pcId(): int
    {
        return (int) $this->validated()['pc_id'];
    }

    public function tariffId(): int
    {
        return (int) $this->validated()['tariff_id'];
    }

    public function clientId(): int
    {
        return (int) $this->validated()['client_id'];
    }
}
