<?php

namespace App\Http\Requests\Api;

use Carbon\Carbon;
use Illuminate\Foundation\Http\FormRequest;

class ShiftHistoryExportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'lang' => ['nullable', 'in:uz,ru,en'],
            'limit' => ['nullable', 'integer', 'min:10', 'max:5000'],
        ];
    }

    public function fromDate(): ?Carbon
    {
        $value = $this->validated()['from'] ?? null;

        return is_string($value) && $value !== ''
            ? Carbon::parse($value)->startOfDay()
            : null;
    }

    public function toDate(): ?Carbon
    {
        $value = $this->validated()['to'] ?? null;

        return is_string($value) && $value !== ''
            ? Carbon::parse($value)->endOfDay()
            : null;
    }

    public function lang(): string
    {
        return (string) ($this->validated()['lang'] ?? 'uz');
    }

    public function limit(): int
    {
        return (int) ($this->validated()['limit'] ?? 2000);
    }
}
