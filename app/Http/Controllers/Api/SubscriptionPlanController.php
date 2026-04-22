<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\StoreSubscriptionPlanRequest;
use App\Http\Requests\Api\SubscriptionPlanIndexRequest;
use App\Http\Requests\Api\UpdateSubscriptionPlanRequest;
use App\Http\Resources\Admin\SubscriptionPlanResource;
use App\Services\SubscriptionPlanCatalogService;
use Illuminate\Http\Request;

class SubscriptionPlanController extends Controller
{
    public function __construct(
        private readonly SubscriptionPlanCatalogService $plans,
    ) {
    }

    public function index(SubscriptionPlanIndexRequest $request)
    {
        $operator = $request->user('operator');
        $plans = $this->plans->paginate(
            (int) $operator->tenant_id,
            $request->filters(),
            $request->perPage(),
        );
        $plans->setCollection(
            collect(SubscriptionPlanResource::collection($plans->getCollection())->resolve())
        );

        return response()->json(['data' => $plans]);
    }

    public function store(StoreSubscriptionPlanRequest $request)
    {
        $operator = $request->user('operator');
        $plan = $this->plans->create(
            (int) $operator->tenant_id,
            $request->payload(),
        );

        return response()->json([
            'data' => (new SubscriptionPlanResource($plan))->resolve(),
        ], 201);
    }

    public function update(UpdateSubscriptionPlanRequest $request, int $id)
    {
        $operator = $request->user('operator');
        $plan = $this->plans->update(
            (int) $operator->tenant_id,
            $id,
            $request->payload(),
        );

        return response()->json([
            'data' => (new SubscriptionPlanResource($plan))->resolve(),
        ]);
    }

    public function toggle(Request $request, int $id)
    {
        $operator = $request->user('operator');
        $plan = $this->plans->toggle(
            (int) $operator->tenant_id,
            $id,
        );

        return response()->json([
            'data' => (new SubscriptionPlanResource($plan))->resolve(),
        ]);
    }
}
