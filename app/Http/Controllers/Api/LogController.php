<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\LogIndexRequest;
use App\Services\ActivityLogService;

class LogController extends Controller
{
    public function __construct(
        private readonly ActivityLogService $logs,
    ) {
    }

    public function index(LogIndexRequest $request)
    {
        $operator = $request->user('operator') ?: $request->user();

        return response()->json(
            $this->logs->paginate(
                (int) $operator->tenant_id,
                $request->fromDate(),
                $request->toDate(),
                $request->filters(),
                $request->page(),
                $request->perPage(),
            )
        );
    }
}
