<?php

namespace App\Http\Controllers\Api;

use App\Actions\Agent\StartAgentSessionAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\StartAgentSessionRequest;
use App\Http\Resources\Agent\AgentSessionStartedResource;

class AgentSessionController extends Controller
{
    public function __construct(
        private readonly StartAgentSessionAction $startSession,
    ) {
    }

    public function start(StartAgentSessionRequest $request)
    {
        // PC va tenant konteksti middleware'dan keladi
        $tenantId = (int) $request->attributes->get('tenant_id');
        $pcId     = (int) $request->attributes->get('pc_id');

        $session = $this->startSession->execute(
            $tenantId,
            $pcId,
            $request->clientId(),
            $request->tariffId(),
        );

        return (new AgentSessionStartedResource($session))
            ->response()
            ->setStatusCode(201);
    }
}
