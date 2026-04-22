<?php

namespace App\Http\Requests\Api;

class MobileOpenQrRequest extends MobileClientContextRequest
{
    public function rules(): array
    {
        return [
            'pc_id' => ['required', 'integer', 'min:1'],
            'code' => ['required', 'string', 'max:128'],
        ];
    }

    public function pcId(): int
    {
        return (int) $this->validated()['pc_id'];
    }

    public function code(): string
    {
        return (string) $this->validated()['code'];
    }
}
