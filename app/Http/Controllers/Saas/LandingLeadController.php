<?php

namespace App\Http\Controllers\Saas;

use App\Http\Controllers\Controller;
use App\Http\Requests\Saas\LandingLeadIndexRequest;
use App\Http\Requests\Saas\UpdateLandingLeadRequest;
use App\Http\Resources\Saas\LandingLeadResource;
use App\Services\LandingLeadService;

class LandingLeadController extends Controller
{
    public function __construct(
        private readonly LandingLeadService $leads,
    ) {
    }

    public function index(LandingLeadIndexRequest $request)
    {
        $leads = $this->leads->paginate($request->filters());
        $leads->setCollection(
            collect(LandingLeadResource::collection($leads->getCollection())->resolve())
        );

        return response()->json(['data' => $leads]);
    }

    public function update(UpdateLandingLeadRequest $request, int $id)
    {
        return response()->json([
            'data' => new LandingLeadResource(
                $this->leads->updateStatus($id, $request->payload()['status'])
            ),
        ]);
    }
}
