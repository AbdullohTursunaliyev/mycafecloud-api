<?php

namespace App\Http\Controllers\Api;

use App\Actions\Session\StartOperatorSessionAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\StartOperatorSessionRequest;
use App\Http\Resources\Session\ActiveSessionResource;
use App\Http\Resources\Session\OperatorSessionStartedResource;
use App\Services\ClientSessionService;
use App\Services\SessionOverviewService;
use Illuminate\Http\Request;

class SessionController extends Controller
{
    public function __construct(
        private readonly ClientSessionService $sessions,
        private readonly StartOperatorSessionAction $startSession,
        private readonly SessionOverviewService $overview,
    ) {
    }

    public function active(Request $request)
    {
        $tenantId = (int) $request->user()->tenant_id;
        $sessions = ActiveSessionResource::collection(
            $this->overview->active($tenantId)
        )->resolve();

        return response()->json(['data' => $sessions]);
    }

    public function start(StartOperatorSessionRequest $request)
    {
        $tenantId = (int) $request->user()->tenant_id;
        $operatorId = (int) $request->user()->id;

        $session = $this->startSession->execute(
            $tenantId,
            $operatorId,
            $request->pcId(),
            $request->clientId(),
            $request->tariffId(),
        );

        return (new OperatorSessionStartedResource($session))
            ->response()
            ->setStatusCode(201);
    }

    public function stop(Request $request, int $id)
    {
        $tenantId = $request->user()->tenant_id;

        $session = \App\Models\Session::where('tenant_id', $tenantId)
            ->with(['pc','tariff','client'])
            ->findOrFail($id);

        if ($session->status !== 'active') {
            throw \Illuminate\Validation\ValidationException::withMessages(['id' => 'Сессия уже завершена']);
        }

        $session = $this->sessions->stopOperatorSession($session);
        $endedAt = $session->ended_at ?? now();

        return response()->json([
            'data' => [
                'id' => $session->id,
                'ended_at' => $endedAt->toIso8601String(),
                'price_total' => $session->price_total, // billing tick davomida yig'ilgan
            ]
        ]);
    }
}
