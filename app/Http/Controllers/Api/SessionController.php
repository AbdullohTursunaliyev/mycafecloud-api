<?php

namespace App\Http\Controllers\Api;

use App\Actions\Session\StartOperatorSessionAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\StartOperatorSessionRequest;
use App\Http\Resources\Session\ActiveSessionResource;
use App\Http\Resources\Session\SessionLifecycleResource;
use App\Http\Resources\Session\OperatorSessionStartedResource;
use App\Services\ClientSessionService;
use App\Services\SessionOverviewService;
use App\Services\SessionPauseService;
use App\Services\SessionProjectionService;
use App\Services\SessionResumeService;
use Illuminate\Http\Request;

class SessionController extends Controller
{
    public function __construct(
        private readonly ClientSessionService $sessions,
        private readonly StartOperatorSessionAction $startSession,
        private readonly SessionOverviewService $overview,
        private readonly SessionPauseService $pauseService,
        private readonly SessionResumeService $resumeService,
        private readonly SessionProjectionService $projection,
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

    public function pause(Request $request, int $id)
    {
        $tenantId = (int) $request->user()->tenant_id;
        $session = \App\Models\Session::query()
            ->where('tenant_id', $tenantId)
            ->with(['pc.zoneRel', 'client', 'clientPackage.package', 'tariff'])
            ->findOrFail($id);

        $session = $this->pauseService->pause($session);
        $session->loadMissing(['pc.zoneRel', 'client', 'clientPackage.package', 'tariff']);

        return response()->json([
            'data' => (new SessionLifecycleResource(
                $this->sessionLifecyclePayload($session)
            ))->resolve(),
        ]);
    }

    public function resume(Request $request, int $id)
    {
        $tenantId = (int) $request->user()->tenant_id;
        $session = \App\Models\Session::query()
            ->where('tenant_id', $tenantId)
            ->with(['pc.zoneRel', 'client', 'clientPackage.package', 'tariff'])
            ->findOrFail($id);

        $session = $this->resumeService->resume($session);
        $session->loadMissing(['pc.zoneRel', 'client', 'clientPackage.package', 'tariff']);

        return response()->json([
            'data' => (new SessionLifecycleResource(
                $this->sessionLifecyclePayload($session)
            ))->resolve(),
        ]);
    }

    private function sessionLifecyclePayload(\App\Models\Session $session): array
    {
        $pc = $session->pc;
        $client = $session->client;
        $projection = $pc ? $this->projection->describe($session, $client, $pc) : [
            'seconds_left' => 0,
            'from' => 'balance',
            'zone_name' => null,
            'rate_per_hour' => 0,
            'next_charge_at' => null,
            'paused' => $session->paused_at !== null,
            'pricing_rule' => null,
        ];

        return [
            'id' => (int) $session->id,
            'status' => (string) $session->status,
            'pc_id' => (int) $session->pc_id,
            'client_id' => (int) $session->client_id,
            'started_at' => $session->started_at?->toIso8601String(),
            'paused_at' => $session->paused_at?->toIso8601String(),
            'last_billed_at' => $session->last_billed_at?->toIso8601String(),
            'price_total' => (int) $session->price_total,
            'seconds_left' => (int) ($projection['seconds_left'] ?? 0),
            'from' => (string) ($projection['from'] ?? 'balance'),
            'zone' => $projection['zone_name'] ?? null,
            'rate_per_hour' => (int) ($projection['rate_per_hour'] ?? 0),
            'next_charge_at' => $projection['next_charge_at'] ?? null,
            'paused' => (bool) ($projection['paused'] ?? false),
            'pricing_rule' => $projection['pricing_rule'] ?? null,
        ];
    }
}
