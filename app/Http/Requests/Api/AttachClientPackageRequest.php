<?php

namespace App\Http\Requests\Api;

use App\Enums\PaymentMethod;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AttachClientPackageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'package_id' => ['required', 'integer'],
            'payment_method' => ['required', 'string', Rule::in(PaymentMethod::values())],
        ];
    }

    public function packageId(): int
    {
        return (int) $this->validated()['package_id'];
    }

    public function paymentMethod(): string
    {
        return (string) $this->validated()['payment_method'];
    }
}
