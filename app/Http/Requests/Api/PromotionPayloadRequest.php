<?php

namespace App\Http\Requests\Api;

use App\Enums\PaymentMethod;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

abstract class PromotionPayloadRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        $required = $this->isPartial() ? 'sometimes' : 'required';

        return [
            'name' => [$required, 'string', 'min:3', 'max:120'],
            'type' => [$required, 'string', Rule::in(['double_topup'])],
            'applies_payment_method' => [$required, 'string', Rule::in(PaymentMethod::promotionValues())],
            'priority' => [$this->isPartial() ? 'sometimes' : 'nullable', 'integer', 'min:0', 'max:100000'],
            'is_active' => [$this->isPartial() ? 'sometimes' : 'nullable', 'boolean'],
            'days_of_week' => [$this->isPartial() ? 'sometimes' : 'nullable', 'array'],
            'days_of_week.*' => ['integer', Rule::in([0, 1, 2, 3, 4, 5, 6])],
            'time_from' => [$this->isPartial() ? 'sometimes' : 'nullable', 'date_format:H:i'],
            'time_to' => [$this->isPartial() ? 'sometimes' : 'nullable', 'date_format:H:i'],
            'starts_at' => [$this->isPartial() ? 'sometimes' : 'nullable', 'date'],
            'ends_at' => [$this->isPartial() ? 'sometimes' : 'nullable', 'date'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $startsAt = $this->input('starts_at');
            $endsAt = $this->input('ends_at');

            if (!$startsAt || !$endsAt) {
                return;
            }

            if (strtotime((string) $startsAt) > strtotime((string) $endsAt)) {
                $validator->errors()->add('starts_at', 'Неверный период');
            }
        });
    }

    public function payload(): array
    {
        $data = $this->validated();

        if (array_key_exists('days_of_week', $data) && empty($data['days_of_week'])) {
            $data['days_of_week'] = null;
        }

        if (array_key_exists('priority', $data) && $data['priority'] === null) {
            $data['priority'] = 100;
        }

        return $data;
    }

    abstract protected function isPartial(): bool;
}
