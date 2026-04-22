<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\BulkClientTopupRequest;
use App\Http\Requests\Api\ClientTopupRequest;
use App\Http\Requests\Api\StoreClientRequest;
use App\Services\ClientAdminService;
use Illuminate\Http\Request;

class ClientController extends Controller
{
    public function __construct(
        private readonly ClientAdminService $clients,
    ) {
    }

    public function index(Request $request)
    {
        return response()->json([
            'data' => $this->clients->paginate((int) $request->user()->tenant_id, [
                'status' => $request->input('status'),
                'search' => $request->input('search'),
            ]),
        ]);
    }

    public function store(StoreClientRequest $request)
    {
        return response()->json([
            'data' => $this->clients->create((int) $request->user()->tenant_id, $request->payload()),
        ], 201);
    }

    public function topup(ClientTopupRequest $request, int $id)
    {
        return response()->json([
            'data' => $this->clients->topup(
                (int) $request->user()->tenant_id,
                (int) $request->user()->id,
                $id,
                $request->payload(),
            ),
        ]);
    }

    public function bulkTopup(BulkClientTopupRequest $request)
    {
        return response()->json([
            'data' => $this->clients->bulkTopup(
                (int) $request->user()->tenant_id,
                (int) $request->user()->id,
                $request->payload(),
            ),
        ]);
    }

    public function history(Request $request, int $id)
    {
        return response()->json([
            'data' => $this->clients->history(
                (int) $request->user()->tenant_id,
                $id,
                $request->query('date'),
            ),
        ]);
    }

    public function sessions(Request $request, int $id)
    {
        return response()->json([
            'data' => $this->clients->sessions(
                (int) $request->user()->tenant_id,
                $id,
                $request->query('date'),
            ),
        ]);
    }

    public function packages(Request $request, int $id)
    {
        return response()->json([
            'data' => $this->clients->packages((int) $request->user()->tenant_id, $id),
        ]);
    }
}
