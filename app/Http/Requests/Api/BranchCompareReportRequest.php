<?php

namespace App\Http\Requests\Api;

class BranchCompareReportRequest extends ReportRangeRequest
{
    public function rules(): array
    {
        return array_merge(parent::rules(), [
            'license_key' => ['required', 'string', 'max:120'],
        ]);
    }
}
