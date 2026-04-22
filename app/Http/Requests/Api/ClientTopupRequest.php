<?php

namespace App\Http\Requests\Api;

use App\Enums\PaymentMethod;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ClientTopupRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'amount' => ['required', 'integer', 'min:1'],
            'payment_method' => ['required', 'string', Rule::in(PaymentMethod::values())],
            'bonus_amount' => ['nullable', 'integer', 'min:0'],
            'comment' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function payload(): array
    {
        return $this->validated();
    }
}
