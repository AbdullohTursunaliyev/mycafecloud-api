<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

class MobileClientContextRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [];
    }

    public function tenantId(): int
    {
        return (int) $this->attributes->get('tenant_id');
    }

    public function clientId(): int
    {
        return (int) $this->attributes->get('client_id');
    }
}
