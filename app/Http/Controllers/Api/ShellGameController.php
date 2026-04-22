<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\AdminShellGamesIndexRequest;
use App\Http\Requests\Api\SetPcShellGameStateRequest;
use App\Http\Requests\Api\ShellGamesIndexRequest;
use App\Http\Requests\Api\StoreShellGameRequest;
use App\Http\Requests\Api\UpdateShellGameRequest;
use App\Http\Resources\Shell\PcShellGameStateResource;
use App\Http\Resources\Shell\ShellGameAdminResource;
use App\Http\Resources\Shell\ShellGamePublicResource;
use App\Services\ShellGameCatalogService;
use Illuminate\Http\Request;

class ShellGameController extends Controller
{
    public function __construct(
        private readonly ShellGameCatalogService $catalog,
    ) {
    }

    public function index(ShellGamesIndexRequest $request)
    {
        $payload = $this->catalog->publicCatalog(
            (int) $request->attributes->get('tenant_id'),
            $request->pcCode(),
            (int) $request->attributes->get('client_id', 0),
        );
        $payload['data'] = ShellGamePublicResource::collection(collect($payload['data']))->resolve();

        return response()->json($payload);
    }

    public function adminIndex(AdminShellGamesIndexRequest $request)
    {
        $tenantId = (int) $request->user()->tenant_id;
        $payload = $this->catalog->adminCatalog($tenantId, $request->pcId());
        $payload['data'] = ShellGameAdminResource::collection(collect($payload['data']))->resolve();

        return response()->json($payload);
    }

    public function store(StoreShellGameRequest $request)
    {
        $tenantId = (int) $request->user()->tenant_id;
        $row = $this->catalog->create($tenantId, $request->payload());

        return response()->json([
            'data' => (new ShellGameAdminResource($row->toArray()))->resolve(),
        ], 201);
    }

    public function update(UpdateShellGameRequest $request, int $id)
    {
        $tenantId = (int) $request->user()->tenant_id;
        $row = $this->catalog->update($tenantId, $id, $request->payload());

        return response()->json([
            'data' => (new ShellGameAdminResource($row->toArray()))->resolve(),
        ]);
    }

    public function toggle(Request $request, int $id)
    {
        $tenantId = (int) $request->user()->tenant_id;
        $row = $this->catalog->toggle($tenantId, $id);

        return response()->json([
            'data' => $row,
        ]);
    }

    public function setPcState(SetPcShellGameStateRequest $request, int $pcId, int $gameId)
    {
        $tenantId = (int) $request->user()->tenant_id;
        $row = $this->catalog->setPcState($tenantId, $pcId, $gameId, $request->payload());

        return response()->json([
            'data' => (new PcShellGameStateResource($row))->resolve(),
        ]);
    }
}
