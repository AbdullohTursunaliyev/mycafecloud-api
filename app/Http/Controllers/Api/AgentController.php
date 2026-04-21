<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Pc;
use App\Models\PcCommand;
use App\Models\PcDeviceToken;
use App\Models\PcHeartbeat;
use App\Models\PcPairCode;
use App\Models\Zone;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AgentController extends Controller
{
    // 4.1 Pair: agent birinchi marta keladi (pair_code bilan)
    public function pair(Request $request)
    {
        $data = $request->validate([
            'pair_code' => ['required','string'],
            'pc_name'   => ['nullable','string','max:64'],
            'ip'        => ['nullable','ip'],
            'mac'       => ['nullable','string','max:32'],
            'os'        => ['nullable','string','max:64'],
        ]);

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

        // PC yaratamiz (code avtomatik)
        $pcCode = $data['pc_name'] ?: ('PC-' . Str::upper(Str::random(4)));

        // tenant ichida unikallik uchun:
        $base = $pcCode;
        $i = 1;
        while (Pc::where('tenant_id', $pair->tenant_id)->where('code', $pcCode)->exists()) {
            $pcCode = $base . '-' . $i++;
        }

        $zoneId = null;
        if ($pair->zone) {
            $zoneId = Zone::query()
                ->where('tenant_id', $pair->tenant_id)
                ->where('name', $pair->zone)
                ->value('id');
        }

        $pc = Pc::create([
            'tenant_id' => $pair->tenant_id,
            'code' => $pcCode,
            'status' => 'online',
            'ip_address' => $data['ip'] ?? null,
            'last_seen_at' => now(),
            'zone_id' => $zoneId,
            'zone'    => $pair->zone,
        ]);

        // device token beramiz
        $plain = Str::random(48);
        PcDeviceToken::create([
            'tenant_id' => $pair->tenant_id,
            'pc_id' => $pc->id,
            'token_hash' => hash('sha256', $plain),
            'last_used_at' => now(),
        ]);

        $pair->update(['used_at' => now(), 'pc_id' => $pc->id]);

        return response()->json([
            'pc' => [
                'id' => $pc->id,
                'code' => $pc->code,
                'zone' => $pc->zone,
            ],
            'device_token' => $plain,
            'poll_interval_sec' => 3,
        ], 201);
    }

    // 4.2 Heartbeat: agent doimiy yuboradi
    public function heartbeat(Request $request)
    {
        $tenantId = (int) $request->attributes->get('tenant_id');
        $pcId     = (int) $request->attributes->get('pc_id');

        $data = $request->validate([
            'ip' => ['nullable','ip'],
            'status' => ['nullable','string','in:online,busy,locked,maintenance'],
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
        $safeStatus = in_array($allowed, ['online','locked','maintenance'], true)
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

        return response()->json(['ok' => true]);
    }

    // 4.2.1 Settings for agent auto-update
    public function settings(Request $request)
    {
        $tenantId = (int) $request->attributes->get('tenant_id');

        return response()->json([
            'settings' => [
                'deploy_agent_download_url' => (string) \App\Service\SettingService::get($tenantId, 'deploy_agent_download_url', ''),
                'deploy_agent_sha256' => (string) \App\Service\SettingService::get($tenantId, 'deploy_agent_sha256', ''),
                'deploy_shell_download_url' => (string) \App\Service\SettingService::get($tenantId, 'deploy_shell_download_url', ''),
                'deploy_shell_sha256' => (string) \App\Service\SettingService::get($tenantId, 'deploy_shell_sha256', ''),
                'deploy_agent_install_args' => (string) \App\Service\SettingService::get($tenantId, 'deploy_agent_install_args', ''),
                'deploy_shell_install_args' => (string) \App\Service\SettingService::get($tenantId, 'deploy_shell_install_args', ''),
            ],
        ]);
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

        return response()->json([
            'commands' => $commands->map(fn($c) => [
                'id' => $c->id,
                'type' => $c->type,
                'payload' => $c->payload,
            ]),
        ]);
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

        return response()->json(['ok' => true]);
    }
}
