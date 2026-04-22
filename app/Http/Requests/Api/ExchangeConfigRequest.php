<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

class ExchangeConfigRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'enabled' => ['nullable', 'boolean'],
            'radius_km' => ['nullable', 'numeric', 'min:1', 'max:300'],
            'min_free_pcs' => ['nullable', 'integer', 'min:0', 'max:1000'],
            'referral_bonus_uzs' => ['nullable', 'integer', 'min:0', 'max:10000000'],
            'overflow_enabled' => ['nullable', 'boolean'],
            'auction_floor_uzs' => ['nullable', 'integer', 'min:0', 'max:10000000'],
            'auction_ceiling_uzs' => ['nullable', 'integer', 'min:0', 'max:10000000'],
        ];
    }
}
