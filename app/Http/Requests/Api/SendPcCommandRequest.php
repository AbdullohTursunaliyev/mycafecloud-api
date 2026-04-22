<?php

namespace App\Http\Requests\Api;

use App\Enums\PcCommandType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SendPcCommandRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'type' => ['required', 'string', Rule::in(PcCommandType::rolloutValues())],
            'payload' => ['nullable', 'array'],
            'payload.text' => ['nullable', 'string', 'max:500'],
            'batch_id' => ['nullable', 'string', 'max:64'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            if ((string) $this->input('type') === PcCommandType::Message->value && !is_string(data_get($this->input('payload'), 'text'))) {
                $validator->errors()->add('payload.text', 'The payload.text field is required.');
            }
        });
    }

    public function commandType(): string
    {
        return (string) $this->validated()['type'];
    }

    public function payload(): ?array
    {
        $payload = $this->validated()['payload'] ?? null;

        return is_array($payload) ? $payload : null;
    }

    public function batchId(): ?string
    {
        $batchId = $this->validated()['batch_id'] ?? null;

        return is_string($batchId) && $batchId !== '' ? $batchId : null;
    }
}
