<?php

namespace App\Http\Requests\Api;

class MobileQuickRebookRequest extends MobileClientContextRequest
{
    public function rules(): array
    {
        return [
            'start_at' => ['nullable', 'date'],
            'hold_minutes' => ['nullable', 'integer', 'min:15', 'max:240'],
        ];
    }

    public function startAt(): ?string
    {
        $value = $this->validated()['start_at'] ?? null;

        return is_string($value) ? $value : null;
    }

    public function holdMinutes(): ?int
    {
        $value = $this->validated()['hold_minutes'] ?? null;

        return $value === null ? null : (int) $value;
    }
}
