<?php

namespace App\Http\Requests\Api;

class ApplyAutopilotRequest extends ReportRangeRequest
{
    public function rules(): array
    {
        return array_merge(parent::rules(), [
            'strategy' => ['nullable', 'string', 'in:balanced,growth,aggressive'],
            'apply_zone_prices' => ['nullable', 'boolean'],
            'apply_promotion' => ['nullable', 'boolean'],
            'enable_beast_mode' => ['nullable', 'boolean'],
            'dry_run' => ['nullable', 'boolean'],
        ]);
    }
}
