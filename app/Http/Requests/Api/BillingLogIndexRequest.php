<?php

namespace App\Http\Requests\Api;

use Carbon\Carbon;
use Illuminate\Foundation\Http\FormRequest;

class BillingLogIndexRequest extends FormRequest
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
            'session_id' => ['nullable', 'integer', 'min:1'],
            'client_id' => ['nullable', 'integer', 'min:1'],
            'pc_id' => ['nullable', 'integer', 'min:1'],
            'mode' => ['nullable', 'in:wallet,package'],
            'search' => ['nullable', 'string', 'max:120'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:200'],
            'summary' => ['nullable', 'boolean'],
        ];
    }

    public function filters(): array
    {
        $validated = $this->validated();

        return [
            'from' => !empty($validated['from']) ? Carbon::parse($validated['from'])->startOfDay() : null,
            'to' => !empty($validated['to']) ? Carbon::parse($validated['to'])->endOfDay() : null,
            'session_id' => isset($validated['session_id']) ? (int) $validated['session_id'] : null,
            'client_id' => isset($validated['client_id']) ? (int) $validated['client_id'] : null,
            'pc_id' => isset($validated['pc_id']) ? (int) $validated['pc_id'] : null,
            'mode' => $validated['mode'] ?? null,
            'search' => $validated['search'] ?? null,
        ];
    }

    public function wantsSummary(): bool
    {
        return $this->boolean('summary');
    }

    public function perPage(): int
    {
        return (int) ($this->validated()['per_page'] ?? 50);
    }
}
