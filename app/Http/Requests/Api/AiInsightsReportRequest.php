<?php

namespace App\Http\Requests\Api;

class AiInsightsReportRequest extends ReportRangeRequest
{
    public function rules(): array
    {
        return array_merge(parent::rules(), [
            'range' => ['nullable', 'string', 'in:all'],
        ]);
    }
}
