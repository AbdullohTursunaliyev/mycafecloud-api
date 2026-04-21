<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PcCommand;
use App\Models\PcHeartbeat;
use Illuminate\Http\Request;

class PcHeartbeatController extends Controller
{
    private const DELIVERY_TYPES = [
        'LOCK',
        'UNLOCK',
        'REBOOT',
        'SHUTDOWN',
        'MESSAGE',
        'INSTALL_GAME',
        'UPDATE_GAME',
        'ROLLBACK_GAME',
        'UPDATE_SHELL',
        'RUN_SCRIPT',
    ];

    public function heartbeat(Request $request)
    {
        $data = $request->validate([
            'license_key' => ['required','string'],
            'pc_code'     => ['required','string'],
            'metrics' => ['nullable','array'],
            'metrics.cpu_name' => ['nullable','string','max:200'],
            'metrics.ram_total_mb' => ['nullable','integer','min:0'],
            'metrics.gpu_name' => ['nullable','string','max:200'],
            'metrics.mac_address' => ['nullable','string','max:64'],
            'metrics.ip_address' => ['nullable','ip'],
            'metrics.disks' => ['nullable','array','max:12'],
            'metrics.disks.*.name' => ['nullable','string','max:10'],
            'metrics.disks.*.total_gb' => ['nullable','numeric','min:0'],
            'metrics.disks.*.free_gb' => ['nullable','numeric','min:0'],
            'metrics.disks.*.used_percent' => ['nullable','numeric','min:0','max:100'],
        ]);

        $license = \App\Models\LicenseKey::with('tenant')
            ->where('key', $data['license_key'])
            ->first();

        if (!$license || $license->status !== 'active' || ($license->expires_at && $license->expires_at->isPast())) {
            return response()->json(['message' => 'License invalid'], 403);
        }
        if ($license->tenant?->status !== 'active') {
            return response()->json(['message' => 'Tenant blocked'], 403);
        }

        $tenantId = $license->tenant_id;

        $pc = \App\Models\Pc::where('tenant_id', $tenantId)
            ->where('code', $data['pc_code'])
            ->first();

        if (!$pc) {
            return response()->json(['message' => 'PC not found'], 404);
        }

        // ✅ heartbeat field (qaysi nom bo‘lsa, shuni ishlatamiz)
        // ko‘pincha: last_heartbeat_at yoki last_seen_at bo‘ladi
        if (property_exists($pc, 'last_heartbeat_at') || \Illuminate\Support\Facades\Schema::hasColumn('pcs','last_heartbeat_at')) {
            $pc->last_heartbeat_at = now();
        } elseif (\Illuminate\Support\Facades\Schema::hasColumn('pcs','last_seen_at')) {
            $pc->last_seen_at = now();
        } elseif (\Illuminate\Support\Facades\Schema::hasColumn('pcs','last_seen')) {
            $pc->last_seen = now();
        }

        // status online/offline bo‘lsa online qilib qo‘yamiz
        if (\Illuminate\Support\Facades\Schema::hasColumn('pcs','status')) {
            if (in_array($pc->status, ['offline','locked'], true)) {
                // sessiya active bo‘lsa locked qolishi mumkin, shuning uchun majburlamaymiz.
                // faqat offline bo‘lsa online qilamiz
                if ($pc->status === 'offline') $pc->status = 'online';
            }
        }

        if (!empty($data['metrics']['ip_address']) && \Illuminate\Support\Facades\Schema::hasColumn('pcs','ip_address')) {
            $pc->ip_address = $data['metrics']['ip_address'];
        }

        $pc->save();

        if (!empty($data['metrics']) && is_array($data['metrics'])) {
            PcHeartbeat::create([
                'tenant_id' => $tenantId,
                'pc_id' => $pc->id,
                'received_at' => now(),
                'metrics' => $data['metrics'],
            ]);
        }

        $activeSessionStartedAt = \App\Models\Session::query()
            ->where('tenant_id', $tenantId)
            ->where('pc_id', $pc->id)
            ->whereNull('ended_at')
            ->latest('id')
            ->value('started_at');

        if ($activeSessionStartedAt) {
            // Eski sessiyadan qolib ketgan LOCK commandlar yangi sessiyani uzib yubormasin.
            PcCommand::query()
                ->where('tenant_id', $tenantId)
                ->where('pc_id', $pc->id)
                ->where('status', 'pending')
                ->where('type', 'LOCK')
                ->where('created_at', '<', $activeSessionStartedAt)
                ->update([
                    'status' => 'failed',
                    'ack_at' => now(),
                    'error' => 'stale_lock_ignored_active_session',
                ]);
        }

        $commandPayload = null;
        $cmdQuery = PcCommand::query()
            ->where('tenant_id', $tenantId)
            ->where('pc_id', $pc->id)
            ->where('status', 'pending')
            ->whereIn('type', self::DELIVERY_TYPES)
            ->orderBy('id');

        if ($activeSessionStartedAt) {
            $cmdQuery->where(function ($q) use ($activeSessionStartedAt) {
                $q->where('type', '!=', 'LOCK')
                    ->orWhere('created_at', '>=', $activeSessionStartedAt);
            });
        }

        $cmd = $cmdQuery->first();

        if ($cmd) {
            $cmd->status = 'sent';
            $cmd->sent_at = now();
            $cmd->save();

            $commandPayload = [
                'id' => $cmd->id,
                'type' => $cmd->type,
                'payload' => $cmd->payload,
            ];
        }

        return response()->json([
            'ok' => true,
            'command' => $commandPayload,
        ]);
    }

    public function ack(Request $request)
    {
        $data = $request->validate([
            'license_key' => ['required', 'string'],
            'pc_code' => ['required', 'string'],
            'command_id' => ['required', 'integer'],
            'status' => ['required', 'string', 'in:done,failed'],
            'error' => ['nullable', 'string', 'max:2000'],
        ]);

        $license = \App\Models\LicenseKey::with('tenant')
            ->where('key', $data['license_key'])
            ->first();

        if (!$license || $license->status !== 'active' || ($license->expires_at && $license->expires_at->isPast())) {
            return response()->json(['message' => 'License invalid'], 403);
        }
        if ($license->tenant?->status !== 'active') {
            return response()->json(['message' => 'Tenant blocked'], 403);
        }

        $tenantId = (int)$license->tenant_id;

        $pc = \App\Models\Pc::query()
            ->where('tenant_id', $tenantId)
            ->where('code', (string)$data['pc_code'])
            ->first();

        if (!$pc) {
            return response()->json(['message' => 'PC not found'], 404);
        }

        $cmd = PcCommand::query()
            ->where('tenant_id', $tenantId)
            ->where('pc_id', (int)$pc->id)
            ->where('id', (int)$data['command_id'])
            ->first();

        if (!$cmd) {
            return response()->json(['message' => 'Command not found'], 404);
        }

        // idempotent ack
        if (!in_array((string)$cmd->status, ['done', 'failed'], true)) {
            $cmd->status = (string)$data['status'];
            $cmd->ack_at = now();
            $cmd->error = $data['status'] === 'failed'
                ? ((string)($data['error'] ?? 'Agent execution failed'))
                : null;
            $cmd->save();
        }

        return response()->json([
            'ok' => true,
            'data' => [
                'id' => (int)$cmd->id,
                'status' => (string)$cmd->status,
            ],
        ]);
    }
}
