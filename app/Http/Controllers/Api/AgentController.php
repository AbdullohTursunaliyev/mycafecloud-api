<?php

namespace App\Http\Controllers\Api;

use App\Actions\Agent\PairPcAction;
use App\Enums\PcStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\AgentPairRequest;
use App\Http\Resources\Agent\AgentPairResource;
use App\Http\Resources\Agent\AgentPollResource;
use App\Http\Resources\Agent\AgentSettingsResource;
use App\Models\Pc;
use App\Models\PcCommand;
use App\Models\PcHeartbeat;
use App\Models\PcPairCode;
use App\Services\AgentInstallerBuilder;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AgentController extends Controller
{
    public function __construct(
        private readonly PairPcAction $pairPc,
        private readonly AgentInstallerBuilder $installerBuilder,
    ) {
    }

    // 4.1 Pair: agent birinchi marta keladi (pair_code bilan)
    public function pair(AgentPairRequest $request)
    {
        $data = $request->payload();

        $pair = PcPairCode::where('code', $data['pair_code'])->first();

        if (!$pair) {
            throw ValidationException::withMessages(['pair_code' => 'Неверный код']);
        }
        if ($pair->used_at) {
            throw ValidationException::withMessages(['pair_code' => 'Код уже использован']);
        }
        if ($pair->expires_at->lte(Carbon::now())) {
            throw ValidationException::withMessages(['pair_code' => 'Код истёк']);
        }

        return (new AgentPairResource($this->pairPc->execute($pair, $data)))
            ->response()
            ->setStatusCode(201);
    }

    // 4.2 Heartbeat: agent doimiy yuboradi
    public function heartbeat(Request $request)
    {
        $tenantId = (int) $request->attributes->get('tenant_id');
        $pcId     = (int) $request->attributes->get('pc_id');

        $data = $request->validate([
            'ip' => ['nullable','ip'],
            'status' => ['nullable', 'string', Rule::in(PcStatus::agentReportedValues())],
            'metrics' => ['nullable','array'],
            'metrics.cpu' => ['nullable','numeric','min:0','max:100'],
            'metrics.ram' => ['nullable','numeric','min:0','max:100'],
            'metrics.user' => ['nullable','string','max:64'],
            'metrics.game' => ['nullable','string','max:128'],
            'uptime_sec' => ['nullable','integer','min:0'],
        ]);

        $pc = Pc::where('tenant_id', $tenantId)->findOrFail($pcId);

        // statusni agentdan to'liq berib yubormaymiz: busy ni sessiya boshqaradi
        $allowed = $data['status'] ?? null;
        $safeStatus = in_array($allowed, PcStatus::agentWritableValues(), true)
            ? $allowed
            : null;

        $pc->update([
            'ip_address' => $data['ip'] ?? $pc->ip_address,
            'last_seen_at' => now(),
            ...( $safeStatus ? ['status' => $safeStatus] : [] ),
        ]);

        // optional log
        if (!empty($data['metrics'])) {
            PcHeartbeat::create([
                'tenant_id' => $tenantId,
                'pc_id' => $pcId,
                'received_at' => now(),
                'metrics' => $data['metrics'],
            ]);
        }

        return $this->jsonWithRotation($request, ['ok' => true]);
    }

    // 4.2.1 Settings for agent auto-update
    public function settings(Request $request)
    {
        $tenantId = (int) $request->attributes->get('tenant_id');

        return new AgentSettingsResource($this->withRotationPayload($request, [
            'settings' => $this->installerBuilder->buildAgentSettings($tenantId),
        ]));
    }

    // 4.3 Poll commands (MVP): agent buyruqlarni olib turadi
    public function poll(Request $request)
    {
        $tenantId = (int) $request->attributes->get('tenant_id');
        $pcId     = (int) $request->attributes->get('pc_id');

        $commands = PcCommand::where('tenant_id', $tenantId)
            ->where('pc_id', $pcId)
            ->where('status', 'pending')
            ->orderBy('id')
            ->limit(20)
            ->get();

        // pending -> sent
        PcCommand::whereIn('id', $commands->pluck('id'))
            ->update(['status' => 'sent', 'sent_at' => now()]);

        return new AgentPollResource($this->withRotationPayload($request, [
            'commands' => $commands->map(fn($c) => [
                'id' => $c->id,
                'type' => $c->type,
                'payload' => $c->payload,
            ])->values()->all(),
        ]));
    }

    // 4.4 Ack
    public function ack(Request $request)
    {
        $tenantId = (int) $request->attributes->get('tenant_id');
        $pcId     = (int) $request->attributes->get('pc_id');

        $data = $request->validate([
            'items' => ['required','array','max:50'],
            'items.*.id' => ['required','integer'],
            'items.*.status' => ['required','string','in:done,failed'],
            'items.*.error' => ['nullable','string','max:255'],
        ]);

        foreach ($data['items'] as $item) {
            PcCommand::where('tenant_id', $tenantId)
                ->where('pc_id', $pcId)
                ->where('id', $item['id'])
                ->update([
                    'status' => $item['status'],
                    'ack_at' => now(),
                    'error' => $item['error'] ?? null,
                ]);
        }

        return $this->jsonWithRotation($request, ['ok' => true]);
    }

    private function jsonWithRotation(Request $request, array $payload, int $status = 200)
    {
        return response()->json($this->withRotationPayload($request, $payload), $status);
    }

    private function withRotationPayload(Request $request, array $payload): array
    {
        $rotation = $request->attributes->get('rotated_device_token');
        if (!is_array($rotation)) {
            return $payload;
        }

        $payload['device_token'] = $rotation['plain'];
        $payload['device_token_expires_at'] = optional($rotation['token']->expires_at)->toIso8601String();

        return $payload;
    }
}
