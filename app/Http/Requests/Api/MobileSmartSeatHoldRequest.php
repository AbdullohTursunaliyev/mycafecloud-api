<?php

namespace App\Http\Requests\Api;

class MobileSmartSeatHoldRequest extends MobileClientContextRequest
{
    public function rules(): array
    {
        return [
            'pc_id' => ['required', 'integer', 'min:1'],
            'hold_minutes' => ['nullable', 'integer', 'min:10', 'max:30'],
        ];
    }

    public function pcId(): int
    {
        return (int) $this->validated()['pc_id'];
    }

    public function holdMinutes(): ?int
    {
        $value = $this->validated()['hold_minutes'] ?? null;

        return $value === null ? null : (int) $value;
    }
}
