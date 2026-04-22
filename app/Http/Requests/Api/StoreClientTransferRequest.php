<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

class StoreClientTransferRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'to_client_id' => ['required', 'integer'],
            'amount' => ['required', 'integer', 'min:1'],
        ];
    }

    public function toClientId(): int
    {
        return (int) $this->validated()['to_client_id'];
    }

    public function amount(): int
    {
        return (int) $this->validated()['amount'];
    }
}
