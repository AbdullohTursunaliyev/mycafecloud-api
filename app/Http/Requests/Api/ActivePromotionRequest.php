<?php

namespace App\Http\Requests\Api;

use App\Enums\PaymentMethod;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ActivePromotionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'payment_method' => ['nullable', 'string', Rule::in(PaymentMethod::values())],
        ];
    }

    public function paymentMethod(): string
    {
        return (string) ($this->validated()['payment_method'] ?? PaymentMethod::Cash->value);
    }
}
