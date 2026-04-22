<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\SendPcCommandRequest;
use App\Services\PcCommandService;

class PcCommandController extends Controller
{
    public function __construct(
        private readonly PcCommandService $commands,
    ) {
    }

    public function send(SendPcCommandRequest $request, int $pcId)
    {
        $command = $this->commands->send(
            (int) $request->user()->tenant_id,
            $pcId,
            $request->commandType(),
            $request->payload(),
            $request->batchId(),
        );

        return response()->json([
            'data' => ['id' => $command->id],
        ], 201);
    }
}
