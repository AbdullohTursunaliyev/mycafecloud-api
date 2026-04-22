<?php

namespace App\Http\Requests\Api;

class MobilePartyBookRequest extends MobileClientContextRequest
{
    public function rules(): array
    {
        return [
            'pc_ids' => ['required', 'array', 'min:2', 'max:8'],
            'pc_ids.*' => ['required', 'integer', 'min:1', 'distinct'],
            'start_at' => ['nullable', 'date'],
            'hold_minutes' => ['nullable', 'integer', 'min:15', 'max:240'],
        ];
    }

    public function pcIds(): array
    {
        return array_map(static fn($value) => (int) $value, (array) ($this->validated()['pc_ids'] ?? []));
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
