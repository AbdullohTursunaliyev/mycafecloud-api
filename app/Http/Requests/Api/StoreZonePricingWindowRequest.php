<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

class StoreZonePricingWindowRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'name' => ['nullable', 'string', 'min:2', 'max:120'],
            'starts_at' => ['required', 'regex:/^\d{2}:\d{2}(:\d{2})?$/'],
            'ends_at' => ['required', 'regex:/^\d{2}:\d{2}(:\d{2})?$/'],
            'starts_on' => ['nullable', 'date'],
            'ends_on' => ['nullable', 'date'],
            'weekdays' => ['nullable', 'array'],
            'weekdays.*' => ['integer', 'between:1,7'],
            'price_per_hour' => ['required', 'integer', 'min:0', 'max:100000000'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }

    public function payload(): array
    {
        $payload = $this->validated();
        $payload['starts_at'] = $this->normalizeTime($payload['starts_at']);
        $payload['ends_at'] = $this->normalizeTime($payload['ends_at']);
        $payload['starts_on'] = $this->normalizeDate($payload['starts_on'] ?? null);
        $payload['ends_on'] = $this->normalizeDate($payload['ends_on'] ?? null);
        $payload['weekdays'] = $this->normalizeWeekdays($payload['weekdays'] ?? []);

        return $payload;
    }

    private function normalizeTime(string $value): string
    {
        return strlen($value) === 5 ? $value . ':00' : $value;
    }

    private function normalizeWeekdays(array $weekdays): array
    {
        $normalized = array_map('intval', $weekdays);
        $normalized = array_values(array_unique(array_filter($normalized, fn (int $value) => $value >= 1 && $value <= 7)));
        sort($normalized);

        return $normalized;
    }

    private function normalizeDate(?string $value): ?string
    {
        $value = is_string($value) ? trim($value) : null;

        return $value !== '' ? $value : null;
    }
}
