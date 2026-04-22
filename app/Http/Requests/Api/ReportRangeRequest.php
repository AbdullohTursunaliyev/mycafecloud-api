<?php

namespace App\Http\Requests\Api;

use Carbon\Carbon;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class ReportRangeRequest extends FormRequest
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
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->has('from') || $validator->errors()->has('to')) {
                return;
            }

            [$from, $to] = $this->rawResolvedRange();
            if ($from->diffInDays($to) + 1 > 120) {
                $validator->errors()->add('from', 'Date range must be 120 days or less');
            }
        });
    }

    public function resolvedRange(): array
    {
        $validated = $this->validated();
        return $this->resolveRangeFromPayload($validated);
    }

    private function rawResolvedRange(): array
    {
        return $this->resolveRangeFromPayload($this->all());
    }

    private function resolveRangeFromPayload(array $payload): array
    {
        $to = isset($payload['to'])
            ? Carbon::parse($payload['to'])->endOfDay()
            : now()->endOfDay();

        $from = isset($payload['from'])
            ? Carbon::parse($payload['from'])->startOfDay()
            : $to->copy()->subDays(6)->startOfDay();

        return [$from, $to];
    }
}
