<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Pc;
use App\Models\PcCommand;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class PcCommandController extends Controller
{
    private const ALLOWED_TYPES = [
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
        'APPLY_CLOUD_PROFILE',
        'BACKUP_CLOUD_PROFILE',
    ];

    public function send(Request $request, int $pcId)
    {
        $tenantId = $request->user()->tenant_id;

        $pc = Pc::where('tenant_id', $tenantId)->findOrFail($pcId);

        $data = $request->validate([
            'type' => ['required','string', 'in:' . implode(',', self::ALLOWED_TYPES)],
            'payload' => ['nullable','array'],
            'batch_id' => ['nullable','string','max:64'],
        ]);

        if ($data['type'] === 'MESSAGE') {
            $request->validate([
                'payload.text' => ['required','string','max:500'],
            ]);
        }

        // Busy session paytida shutdown/rebootni cheklash (xohlasang)
        if ($pc->status === 'busy' && in_array($data['type'], ['SHUTDOWN'], true)) {
            throw ValidationException::withMessages(['type' => 'Нельзя выключить ПК во время сессии']);
        }

        $row = [
            'tenant_id' => $tenantId,
            'pc_id' => $pc->id,
            'type' => $data['type'],
            'payload' => $data['payload'] ?? null,
            'status' => 'pending',
        ];
        if (Schema::hasColumn('pc_commands', 'batch_id')) {
            $row['batch_id'] = $data['batch_id'] ?? null;
        }

        $cmd = PcCommand::create($row);

        return response()->json(['data' => ['id' => $cmd->id]], 201);
    }
}
