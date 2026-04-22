<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

class UpdateLayoutGridRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'rows' => ['required', 'integer', 'min:1', 'max:50'],
            'cols' => ['required', 'integer', 'min:1', 'max:50'],
        ];
    }

    public function grid(): array
    {
        $validated = $this->validated();

        return [
            'rows' => (int) $validated['rows'],
            'cols' => (int) $validated['cols'],
        ];
    }
}
