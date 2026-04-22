<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\StoreOperatorRequest;
use App\Http\Requests\Api\UpdateOperatorRequest;
use App\Http\Resources\Admin\OperatorResource;
use App\Services\OperatorAdminService;
use Illuminate\Http\Request;

class OperatorController extends Controller
{
    public function __construct(
        private readonly OperatorAdminService $operators,
    ) {
    }

    public function index(Request $request)
    {
        $operator = $request->user('operator') ?? $request->user();
        $items = OperatorResource::collection(
            $this->operators->list((int) $operator->tenant_id)
        )->resolve();

        return response()->json(['data' => $items]);
    }

    public function store(StoreOperatorRequest $request)
    {
        $operator = $request->user('operator') ?? $request->user();
        $created = $this->operators->create(
            (int) $operator->tenant_id,
            $request->payload(),
        );

        return response()->json([
            'data' => (new OperatorResource($created))->resolve(),
        ], 201);
    }

    public function update(UpdateOperatorRequest $request, int $id)
    {
        $operator = $request->user('operator') ?? $request->user();
        $updated = $this->operators->update(
            (int) $operator->tenant_id,
            $id,
            $request->payload(),
        );

        return response()->json([
            'data' => (new OperatorResource($updated))->resolve(),
        ]);
    }
}
