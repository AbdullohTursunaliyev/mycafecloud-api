<?php

namespace App\Http\Controllers\Saas;

use App\Http\Controllers\Controller;
use App\Http\Requests\Saas\PlanIndexRequest;
use App\Http\Requests\Saas\UpdateSaasPlanRequest;
use App\Http\Resources\Saas\SaasPlanResource;
use App\Services\SaasPlanService;

class SaasPlanController extends Controller
{
    public function __construct(
        private readonly SaasPlanService $plans,
    ) {
    }

    public function index(PlanIndexRequest $request)
    {
        return response()->json([
            'data' => SaasPlanResource::collection($this->plans->list($request->filters())),
        ]);
    }

    public function update(UpdateSaasPlanRequest $request, int $id)
    {
        return response()->json([
            'data' => new SaasPlanResource($this->plans->update($id, $request->payload())),
        ]);
    }
}
