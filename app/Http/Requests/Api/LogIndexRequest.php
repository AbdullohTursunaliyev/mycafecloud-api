<?php

namespace App\Http\Requests\Api;

use Carbon\Carbon;
use Illuminate\Foundation\Http\FormRequest;

class LogIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'type' => ['nullable', 'string', 'max:40'],
            'source' => ['nullable', 'string', 'max:40'],
            'status' => ['nullable', 'string', 'max:40'],
            'search' => ['nullable', 'string', 'max:120'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:200'],
        ];
    }

    public function fromDate(): Carbon
    {
        return isset($this->validated()['from'])
            ? Carbon::parse($this->validated()['from'])->startOfDay()
            : $this->toDate()->copy()->subDays(6)->startOfDay();
    }

    public function toDate(): Carbon
    {
        return isset($this->validated()['to'])
            ? Carbon::parse($this->validated()['to'])->endOfDay()
            : now()->endOfDay();
    }

    public function filters(): array
    {
        $validated = $this->validated();

        return [
            'type' => $validated['type'] ?? null,
            'source' => $validated['source'] ?? null,
            'status' => $validated['status'] ?? null,
            'search' => $validated['search'] ?? null,
        ];
    }

    public function page(): int
    {
        return (int) ($this->validated()['page'] ?? 1);
    }

    public function perPage(): int
    {
        return (int) ($this->validated()['per_page'] ?? 50);
    }
}
