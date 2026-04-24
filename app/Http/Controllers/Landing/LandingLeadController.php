<?php

namespace App\Http\Controllers\Landing;

use App\Http\Controllers\Controller;
use App\Http\Requests\Landing\StoreLandingLeadRequest;
use App\Services\LandingLeadService;

class LandingLeadController extends Controller
{
    public function __construct(
        private readonly LandingLeadService $leads,
    ) {
    }

    public function store(StoreLandingLeadRequest $request)
    {
        $lead = $this->leads->create($request->payload(), $request);

        return response()->json([
            'data' => [
                'id' => (int) $lead->id,
                'status' => (string) $lead->status,
            ],
        ], 201);
    }
}
