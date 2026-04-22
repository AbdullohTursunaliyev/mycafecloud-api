<?php

namespace App\Http\Requests\Api;

class MobileSmartSeatRequest extends MobileClientContextRequest
{
    public function rules(): array
    {
        return [
            'zone_key' => ['nullable', 'string', 'max:96'],
            'arrive_in' => ['nullable', 'integer', 'min:1', 'max:90'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:5'],
        ];
    }

    public function zoneKey(): string
    {
        return (string) ($this->validated()['zone_key'] ?? '');
    }

    public function arriveIn(): int
    {
        return (int) ($this->validated()['arrive_in'] ?? 15);
    }

    public function limit(): int
    {
        return (int) ($this->validated()['limit'] ?? 3);
    }
}
