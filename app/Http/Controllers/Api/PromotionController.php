<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\ActivePromotionRequest;
use App\Http\Requests\Api\PromotionIndexRequest;
use App\Http\Requests\Api\StorePromotionRequest;
use App\Http\Requests\Api\UpdatePromotionRequest;
use App\Http\Resources\Admin\PromotionResource;
use App\Services\PromotionCatalogService;
use Illuminate\Http\Request;

class PromotionController extends Controller
{
    public function __construct(
        private readonly PromotionCatalogService $promotions,
    ) {
    }

    public function index(PromotionIndexRequest $request)
    {
        $operator = $request->user('operator');
        $promotions = $this->promotions->paginate(
            (int) $operator->tenant_id,
            $request->filters(),
            $request->perPage(),
        );
        $promotions->setCollection(
            collect(PromotionResource::collection($promotions->getCollection())->resolve())
        );

        return response()->json([
            'data' => $promotions,
        ]);
    }

    public function store(StorePromotionRequest $request)
    {
        $operator = $request->user('operator');
        $promotion = $this->promotions->create(
            (int) $operator->tenant_id,
            $request->payload(),
        );

        return response()->json([
            'data' => (new PromotionResource($promotion))->resolve(),
        ], 201);
    }

    public function update(UpdatePromotionRequest $request, int $id)
    {
        $operator = $request->user('operator');
        $promotion = $this->promotions->update(
            (int) $operator->tenant_id,
            $id,
            $request->payload(),
        );

        return response()->json([
            'data' => (new PromotionResource($promotion))->resolve(),
        ]);
    }

    public function toggle(Request $request, int $id)
    {
        $operator = $request->user('operator');
        $promotion = $this->promotions->toggle(
            (int) $operator->tenant_id,
            $id,
        );

        return response()->json([
            'data' => (new PromotionResource($promotion))->resolve(),
        ]);
    }

    public function activeForTopup(ActivePromotionRequest $request)
    {
        $tenantId = (int) (auth('operator')->user()?->tenant_id ?? 0);
        $result = $this->promotions->activeForTopup(
            $tenantId,
            $request->paymentMethod(),
        );

        return response()->json([
            'data' => $result['promotion']
                ? (new PromotionResource($result['promotion']))->resolve()
                : null,
            'meta' => $result['meta'],
        ]);
    }
}
