<?php

namespace App\Http\Requests\Api;

class MobileSmartQueueJoinRequest extends MobileClientContextRequest
{
    public function rules(): array
    {
        return [
            'zone_key' => ['nullable', 'string', 'max:96'],
            'notify_on_free' => ['nullable', 'boolean'],
        ];
    }

    public function zoneKey(): ?string
    {
        $value = $this->validated()['zone_key'] ?? null;

        return is_string($value) ? $value : null;
    }

    public function notifyOnFree(): bool
    {
        return array_key_exists('notify_on_free', $this->validated())
            ? (bool) $this->validated()['notify_on_free']
            : true;
    }
}
