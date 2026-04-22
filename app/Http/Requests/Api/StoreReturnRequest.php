<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreReturnRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'type' => ['required', 'string', Rule::in(['topup', 'package'])],
            'source_id' => ['required', 'integer'],
        ];
    }

    public function returnType(): string
    {
        return (string) $this->validated()['type'];
    }

    public function sourceId(): int
    {
        return (int) $this->validated()['source_id'];
    }
}
