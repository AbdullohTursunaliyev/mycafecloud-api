<?php

namespace App\Http\Controllers\Api;

use App\Actions\Deployment\CreateBulkQuickInstallAction;
use App\Actions\Deployment\CreateQuickInstallAction;
use App\Enums\PcCommandType;
use App\Enums\PcStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\BulkQuickInstallRequest;
use App\Http\Requests\Api\QuickInstallRequest;
use App\Models\PcCommand;
use App\Models\PcPairCode;
use App\Http\Resources\Deployment\BulkQuickInstallResource;
use App\Http\Resources\Deployment\QuickInstallResource;
use App\Services\DeploymentScriptService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class DeploymentController extends Controller
{
    public function __construct(
        private readonly CreateQuickInstallAction $createQuickInstall,
        private readonly CreateBulkQuickInstallAction $createBulkQuickInstall,
        private readonly DeploymentScriptService $scripts,
    ) {
    }

    public function quickInstall(QuickInstallRequest $request)
    {
        $tenantId = (int) $request->user()->tenant_id;

        $result = $this->createQuickInstall->execute(
            tenantId: $tenantId,
            baseUrl: (string) (config('app.url') ?: $request->getSchemeAndHttpHost()),
            pcId: $request->pcId(),
            zoneId: $request->zoneId(),
            zoneName: $request->zoneName(),
            expiresInMin: $request->expiresInMin(),
        );

        return (new QuickInstallResource($result))
            ->response()
            ->setStatusCode(201);
    }

    public function quickInstallBulk(BulkQuickInstallRequest $request)
    {
        $tenantId = (int) $request->user()->tenant_id;

        $result = $this->createBulkQuickInstall->execute(
            tenantId: $tenantId,
            baseUrl: (string) (config('app.url') ?: $request->getSchemeAndHttpHost()),
            count: $request->countValue(),
            zoneId: $request->zoneId(),
            zoneName: $request->zoneName(),
            expiresInMin: $request->expiresInMin(),
        );

        return (new BulkQuickInstallResource($result))
            ->response()
            ->setStatusCode(201);
    }

    public function pairCodes(Request $request)
    {
        $tenantId = (int)$request->user()->tenant_id;
        $status = (string)$request->query('status', 'active');
        $limit = max(1, min((int)$request->query('limit', 100), 500));
        if (!in_array($status, ['active', 'used', 'expired', 'all'], true)) {
            throw ValidationException::withMessages([
                'status' => 'status must be one of: active, used, expired, all',
            ]);
        }

        $q = PcPairCode::query()
            ->where('tenant_id', $tenantId)
            ->with('pc:id,code')
            ->orderByDesc('id');

        if ($status === 'active') {
            $q->whereNull('used_at')->where('expires_at', '>', now());
        } elseif ($status === 'used') {
            $q->whereNotNull('used_at');
        } elseif ($status === 'expired') {
            $q->whereNull('used_at')->where('expires_at', '<=', now());
        }

        $rows = $q->limit($limit)->get();

        return response()->json([
            'data' => $rows->map(function (PcPairCode $row) {
                $state = 'active';
                if ($row->used_at) {
                    $state = 'used';
                } elseif ($row->expires_at && $row->expires_at->lte(now())) {
                    $state = 'expired';
                }

                return [
                    'code' => $row->code,
                    'zone' => $row->zone,
                    'state' => $state,
                    'expires_at' => optional($row->expires_at)->toIso8601String(),
                    'used_at' => optional($row->used_at)->toIso8601String(),
                    'pc' => $row->pc ? [
                        'id' => (int)$row->pc->id,
                        'code' => (string)$row->pc->code,
                    ] : null,
                ];
            }),
        ]);
    }

    public function revokePairCode(Request $request, string $code)
    {
        $tenantId = (int)$request->user()->tenant_id;

        $pair = PcPairCode::query()
            ->where('tenant_id', $tenantId)
            ->where('code', $code)
            ->firstOrFail();

        if ($pair->used_at) {
            throw ValidationException::withMessages([
                'code' => 'Used pair code cannot be revoked.',
            ]);
        }

        $pair->expires_at = now()->subSecond();
        $pair->save();

        return response()->json(['ok' => true]);
    }

    public function installerScript(Request $request, string $code)
    {
        $payload = $this->scripts->buildPrivateInstallerScript(
            (int) $request->user()->tenant_id,
            $code,
            (string) (config('app.url') ?: $request->getSchemeAndHttpHost()),
        );

        return response($payload['script'], 200, [
            'Content-Type' => 'text/plain; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="' . $payload['filename'] . '"',
        ]);
    }

    public function publicInstallerScript(Request $request, string $code)
    {
        $payload = $this->scripts->buildPublicInstallerScript(
            $code,
            (string) (config('app.url') ?: $request->getSchemeAndHttpHost()),
        );

        return response($payload['script'], 200, [
            'Content-Type' => 'text/plain; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="' . $payload['filename'] . '"',
        ]);
    }

    public function publicGpoScript(Request $request, string $code)
    {
        $payload = $this->scripts->buildPublicGpoScript(
            $code,
            (string) (config('app.url') ?: $request->getSchemeAndHttpHost()),
        );

        return response($payload['script'], 200, [
            'Content-Type' => 'text/plain; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="' . $payload['filename'] . '"',
        ]);
    }

    public function rollout(Request $request)
    {
        $tenantId = (int)$request->user()->tenant_id;
        $onlineStatuses = $this->onlineStatuses();

        $data = $request->validate([
            'type' => ['required', 'string', 'in:' . implode(',', PcCommandType::rolloutValues())],
            'payload' => ['nullable', 'array'],
            'target_mode' => ['required', 'string', 'in:all,online,zone,selected'],
            'pc_ids' => ['nullable', 'array', 'max:2000'],
            'pc_ids.*' => ['integer'],
            'zone_id' => ['nullable', 'integer'],
            'only_online' => ['nullable', 'boolean'],
            'dry_run' => ['nullable', 'boolean'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:5000'],
        ]);

        $query = Pc::query()->where('tenant_id', $tenantId);
        $mode = $data['target_mode'];

        if ($mode === 'zone') {
            if (empty($data['zone_id'])) {
                throw ValidationException::withMessages(['zone_id' => 'zone_id is required for zone mode']);
            }
            $query->where('zone_id', (int)$data['zone_id']);
        } elseif ($mode === 'selected') {
            $pcIds = array_values(array_unique(array_map('intval', $data['pc_ids'] ?? [])));
            if (count($pcIds) < 1) {
                throw ValidationException::withMessages(['pc_ids' => 'pc_ids is required for selected mode']);
            }
            $query->whereIn('id', $pcIds);
        } elseif ($mode === 'online') {
            $query->whereIn('status', $onlineStatuses);
        }

        $onlyOnline = (bool)($data['only_online'] ?? true);
        if ($onlyOnline) {
            $query->whereIn('status', $onlineStatuses)
                ->where('last_seen_at', '>=', now()->subMinutes((int) config('domain.pc.online_window_minutes', 3)));
        }

        $limit = isset($data['limit']) ? (int)$data['limit'] : null;
        if ($limit !== null) {
            $query->limit($limit);
        }

        $targets = $query->orderBy('id')->get(['id', 'code', 'status', 'last_seen_at']);
        if ($targets->isEmpty()) {
            throw ValidationException::withMessages([
                'target_mode' => 'No target PCs found for this rollout filter.',
            ]);
        }

        if ((bool)($data['dry_run'] ?? false)) {
            return response()->json([
                'data' => [
                    'dry_run' => true,
                    'count' => $targets->count(),
                    'targets' => $targets->map(fn($pc) => [
                        'id' => (int)$pc->id,
                        'code' => (string)$pc->code,
                        'status' => (string)$pc->status,
                        'last_seen_at' => optional($pc->last_seen_at)->toIso8601String(),
                    ]),
                ],
            ]);
        }

        $batchId = (string) Str::ulid();
        $now = now();
        $payload = $data['payload'] ?? null;

        $rows = $targets->map(function ($pc) use ($tenantId, $batchId, $data, $payload, $now) {
            return [
                'tenant_id' => $tenantId,
                'pc_id' => (int)$pc->id,
                'batch_id' => $batchId,
                'type' => $data['type'],
                'payload' => $payload,
                'status' => 'pending',
                'sent_at' => null,
                'ack_at' => null,
                'error' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        })->all();

        PcCommand::insert($rows);

        return response()->json([
            'data' => [
                'batch_id' => $batchId,
                'type' => $data['type'],
                'count' => count($rows),
                'target_mode' => $mode,
                'batch_supported' => true,
            ],
        ], 201);
    }

    public function batches(Request $request)
    {
        $tenantId = (int)$request->user()->tenant_id;
        $limit = max(1, min((int)$request->query('limit', 20), 100));

        $rows = PcCommand::query()
            ->where('tenant_id', $tenantId)
            ->whereNotNull('batch_id')
            ->selectRaw("
                batch_id,
                MAX(type) as type,
                COUNT(*) as total,
                SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending,
                SUM(CASE WHEN status = 'sent' THEN 1 ELSE 0 END) as sent,
                SUM(CASE WHEN status = 'done' THEN 1 ELSE 0 END) as done,
                SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed,
                MAX(created_at) as created_at,
                MAX(ack_at) as last_ack_at
            ")
            ->groupBy('batch_id')
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get();

        return response()->json(['data' => $rows]);
    }

    public function batchStatus(Request $request, string $batchId)
    {
        $tenantId = (int)$request->user()->tenant_id;

        $base = PcCommand::query()
            ->where('tenant_id', $tenantId)
            ->where('batch_id', $batchId);

        if (!(clone $base)->exists()) {
            return response()->json(['message' => 'Batch not found'], 404);
        }

        $summary = (clone $base)
            ->selectRaw("
                MAX(type) as type,
                COUNT(*) as total,
                SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending,
                SUM(CASE WHEN status = 'sent' THEN 1 ELSE 0 END) as sent,
                SUM(CASE WHEN status = 'done' THEN 1 ELSE 0 END) as done,
                SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed,
                MAX(created_at) as created_at,
                MAX(ack_at) as last_ack_at
            ")
            ->first();

        $items = (clone $base)
            ->leftJoin('pcs as p', 'p.id', '=', 'pc_commands.pc_id')
            ->select([
                'pc_commands.id',
                'pc_commands.pc_id',
                'p.code as pc_code',
                'pc_commands.status',
                'pc_commands.error',
                'pc_commands.sent_at',
                'pc_commands.ack_at',
            ])
            ->orderBy('pc_commands.id')
            ->limit(500)
            ->get();

        return response()->json([
            'data' => [
                'batch_id' => $batchId,
                'summary' => $summary,
                'items' => $items,
            ],
        ]);
    }

    public function retryFailed(Request $request, string $batchId)
    {
        $tenantId = (int)$request->user()->tenant_id;
        $failed = PcCommand::query()
            ->where('tenant_id', $tenantId)
            ->where('batch_id', $batchId)
            ->where('status', 'failed')
            ->get(['pc_id', 'type', 'payload']);

        if ($failed->isEmpty()) {
            throw ValidationException::withMessages([
                'batch_id' => 'No failed commands found in this batch.',
            ]);
        }

        $newBatchId = (string) Str::ulid();
        $now = now();

        $rows = $failed->map(fn($row) => [
            'tenant_id' => $tenantId,
            'pc_id' => (int)$row->pc_id,
            'batch_id' => $newBatchId,
            'type' => $row->type,
            'payload' => $row->payload,
            'status' => 'pending',
            'sent_at' => null,
            'ack_at' => null,
            'error' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ])->all();

        PcCommand::insert($rows);

        return response()->json([
            'data' => [
                'source_batch_id' => $batchId,
                'new_batch_id' => $newBatchId,
                'count' => count($rows),
            ],
        ], 201);
    }

    private function onlineStatuses(): array
    {
        return PcStatus::onlineValues();
    }
}
