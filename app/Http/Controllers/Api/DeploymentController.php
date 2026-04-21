<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Pc;
use App\Models\PcCommand;
use App\Models\PcPairCode;
use App\Models\Zone;
use App\Service\SettingService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class DeploymentController extends Controller
{
    private const ROLLOUT_TYPES = [
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

    private const ONLINE_STATUSES = [
        'online',
        'busy',
        'reserved',
        'locked',
        'maintenance',
    ];

    public function quickInstall(Request $request)
    {
        $tenantId = (int)$request->user()->tenant_id;

        $data = $request->validate([
            'zone_id' => ['nullable', 'integer'],
            'zone' => ['nullable', 'string', 'max:32'],
            'expires_in_min' => ['nullable', 'integer', 'min:1', 'max:120'],
        ]);

        $zoneName = $data['zone'] ?? null;
        if (!$zoneName && !empty($data['zone_id'])) {
            $zoneName = Zone::query()
                ->where('tenant_id', $tenantId)
                ->where('id', (int)$data['zone_id'])
                ->value('name');
        }

        $pair = $this->createPairCodeRecord(
            $tenantId,
            $zoneName,
            (int)($data['expires_in_min'] ?? 10)
        );

        $baseUrl = rtrim((string)(config('app.url') ?: $request->getSchemeAndHttpHost()), '/');
        $apiBase = $baseUrl . '/api';
        $script = $this->buildInstallerScript(
            $apiBase,
            $pair->code,
            $request->user()->tenant_id
        );
        $scriptUrl = $apiBase . '/deployment/quick-install/' . urlencode($pair->code) . '/script.ps1';
        $gpoUrl = $apiBase . '/deployment/quick-install/' . urlencode($pair->code) . '/gpo.ps1';

        return response()->json([
            'data' => [
                'pair_code' => $pair->code,
                'zone' => $pair->zone,
                'expires_at' => $pair->expires_at->toIso8601String(),
                'pair_endpoint' => $apiBase . '/agent/pair',
                'installer_script_url' => $scriptUrl,
                'install_one_liner' => $this->buildInstallOneLiner($scriptUrl),
                'gpo_script_url' => $gpoUrl,
                'gpo_one_liner' => $this->buildInstallOneLiner($gpoUrl),
                'installer_script' => $script,
                'quick_test_curl' => sprintf(
                    "curl -X POST \"%s/agent/pair\" -H \"Content-Type: application/json\" -d '{\"pair_code\":\"%s\",\"pc_name\":\"TEST-PC\"}'",
                    $apiBase,
                    $pair->code
                ),
                'powershell_example' => sprintf(
                    '$server="%s"; $code="%s"; $payload=@{pair_code=$code; pc_name=$env:COMPUTERNAME} | ConvertTo-Json; Invoke-RestMethod -Method Post -Uri "$server/agent/pair" -ContentType "application/json" -Body $payload',
                    $apiBase,
                    $pair->code
                ),
            ],
        ], 201);
    }

    public function quickInstallBulk(Request $request)
    {
        $tenantId = (int)$request->user()->tenant_id;

        $data = $request->validate([
            'count' => ['required', 'integer', 'min:1', 'max:300'],
            'zone_id' => ['nullable', 'integer'],
            'zone' => ['nullable', 'string', 'max:32'],
            'expires_in_min' => ['nullable', 'integer', 'min:1', 'max:120'],
        ]);

        $zoneName = $data['zone'] ?? null;
        if (!$zoneName && !empty($data['zone_id'])) {
            $zoneName = Zone::query()
                ->where('tenant_id', $tenantId)
                ->where('id', (int)$data['zone_id'])
                ->value('name');
        }

        $expiresInMin = (int)($data['expires_in_min'] ?? 10);
        $count = (int)$data['count'];
        $baseUrl = rtrim((string)(config('app.url') ?: $request->getSchemeAndHttpHost()), '/');
        $apiBase = $baseUrl . '/api';

        $codes = [];
        for ($i = 0; $i < $count; $i++) {
            $pair = $this->createPairCodeRecord($tenantId, $zoneName, $expiresInMin);
            $codes[] = [
                'pair_code' => $pair->code,
                'zone' => $pair->zone,
                'expires_at' => $pair->expires_at->toIso8601String(),
            ];
        }

        return response()->json([
            'data' => [
                'count' => count($codes),
                'codes' => $codes,
                'installer_script_url_pattern' => $apiBase . '/deployment/quick-install/{PAIR_CODE}/script.ps1',
                'install_one_liner_pattern' => $this->buildInstallOneLiner($apiBase . '/deployment/quick-install/{PAIR_CODE}/script.ps1'),
                'gpo_script_url_pattern' => $apiBase . '/deployment/quick-install/{PAIR_CODE}/gpo.ps1',
                'gpo_one_liner_pattern' => $this->buildInstallOneLiner($apiBase . '/deployment/quick-install/{PAIR_CODE}/gpo.ps1'),
            ],
        ], 201);
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
        $tenantId = (int)$request->user()->tenant_id;
        $pair = $this->findValidPairCode($code);
        if ((int)$pair->tenant_id !== $tenantId) {
            throw ValidationException::withMessages([
                'code' => 'Pair code does not belong to current tenant.',
            ]);
        }

        $baseUrl = rtrim((string)(config('app.url') ?: $request->getSchemeAndHttpHost()), '/');
        $apiBase = $baseUrl . '/api';
        $script = $this->buildInstallerScript($apiBase, $pair->code, $tenantId);

        return response($script, 200, [
            'Content-Type' => 'text/plain; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="mycafecloud-install-' . strtolower($pair->code) . '.ps1"',
        ]);
    }

    public function publicInstallerScript(Request $request, string $code)
    {
        $pair = $this->findValidPairCode($code);

        $baseUrl = rtrim((string)(config('app.url') ?: $request->getSchemeAndHttpHost()), '/');
        $apiBase = $baseUrl . '/api';
        $script = $this->buildInstallerScript($apiBase, $pair->code, (int)$pair->tenant_id);

        return response($script, 200, [
            'Content-Type' => 'text/plain; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="mycafecloud-install-' . strtolower($pair->code) . '.ps1"',
        ]);
    }

    public function publicGpoScript(Request $request, string $code)
    {
        $pair = $this->findValidPairCode($code);

        $baseUrl = rtrim((string)(config('app.url') ?: $request->getSchemeAndHttpHost()), '/');
        $apiBase = $baseUrl . '/api';
        $scriptUrl = $apiBase . '/deployment/quick-install/' . urlencode($pair->code) . '/script.ps1';
        $script = $this->buildGpoScript($scriptUrl);

        return response($script, 200, [
            'Content-Type' => 'text/plain; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="mycafecloud-gpo-' . strtolower($pair->code) . '.ps1"',
        ]);
    }

    public function rollout(Request $request)
    {
        $tenantId = (int)$request->user()->tenant_id;
        $hasBatchColumn = $this->hasBatchIdColumn();

        $data = $request->validate([
            'type' => ['required', 'string', 'in:' . implode(',', self::ROLLOUT_TYPES)],
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
            $query->whereIn('status', self::ONLINE_STATUSES);
        }

        $onlyOnline = (bool)($data['only_online'] ?? true);
        if ($onlyOnline) {
            $query->whereIn('status', self::ONLINE_STATUSES)
                ->where('last_seen_at', '>=', now()->subMinutes(10));
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

        $batchId = $hasBatchColumn ? (string) Str::ulid() : null;
        $now = now();
        $payload = $data['payload'] ?? null;

        $rows = $targets->map(function ($pc) use ($tenantId, $batchId, $data, $payload, $now, $hasBatchColumn) {
            $row = [
                'tenant_id' => $tenantId,
                'pc_id' => (int)$pc->id,
                'type' => $data['type'],
                'payload' => $payload,
                'status' => 'pending',
                'sent_at' => null,
                'ack_at' => null,
                'error' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ];

            if ($hasBatchColumn) {
                $row['batch_id'] = $batchId;
            }

            return $row;
        })->all();

        PcCommand::insert($rows);

        return response()->json([
            'data' => [
                'batch_id' => $batchId,
                'type' => $data['type'],
                'count' => count($rows),
                'target_mode' => $mode,
                'batch_supported' => $hasBatchColumn,
            ],
        ], 201);
    }

    public function batches(Request $request)
    {
        $tenantId = (int)$request->user()->tenant_id;
        $limit = max(1, min((int)$request->query('limit', 20), 100));
        if (!$this->hasBatchIdColumn()) {
            return response()->json([
                'data' => [],
                'meta' => [
                    'batch_supported' => false,
                    'message' => 'batch_id column missing. Run migrations.',
                ],
            ]);
        }

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
        if (!$this->hasBatchIdColumn()) {
            return response()->json([
                'message' => 'batch_id column missing. Run migrations.',
            ], 409);
        }

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
        if (!$this->hasBatchIdColumn()) {
            return response()->json([
                'message' => 'batch_id column missing. Run migrations.',
            ], 409);
        }

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

    private function generatePairCode(): string
    {
        do {
            $code = Str::upper(Str::random(4)) . '-' . Str::upper(Str::random(2));
        } while (PcPairCode::query()->where('code', $code)->exists());

        return $code;
    }

    private function createPairCodeRecord(int $tenantId, ?string $zoneName, int $expiresInMin): PcPairCode
    {
        return PcPairCode::create([
            'tenant_id' => $tenantId,
            'code' => $this->generatePairCode(),
            'zone' => $zoneName,
            'expires_at' => now()->addMinutes($expiresInMin),
        ]);
    }

    private function buildInstallerScript(string $apiBase, string $pairCode, int $tenantId): string
    {
        $downloadUrl = trim((string)SettingService::get($tenantId, 'deploy_agent_download_url', ''));
        $installArgs = trim((string)SettingService::get($tenantId, 'deploy_agent_install_args', '--install SERVER_URL="{SERVER}" PAIR_CODE="{PAIR_CODE}"'));
        $clientUrl = trim((string)SettingService::get($tenantId, 'deploy_client_download_url', ''));
        $clientArgs = trim((string)SettingService::get($tenantId, 'deploy_client_install_args', ''));
        $shellUrl = trim((string)SettingService::get($tenantId, 'deploy_shell_download_url', ''));
        $shellArgs = trim((string)SettingService::get($tenantId, 'deploy_shell_install_args', '/quiet SERVER_URL="{SERVER}"'));
        $shellAutoStartEnabled = SettingService::get($tenantId, 'shell_autostart_enabled', false) ? '1' : '0';
        $shellAutoStartPath = trim((string)SettingService::get($tenantId, 'shell_autostart_path', ''));
        $shellAutoStartArgs = trim((string)SettingService::get($tenantId, 'shell_autostart_args', ''));
        $shellAutoStartScope = trim((string)SettingService::get($tenantId, 'shell_autostart_scope', 'machine'));

        $shellReplaceEnabled = SettingService::get($tenantId, 'shell_replace_explorer_enabled', false) ? '1' : '0';
        $shellReplacePath = trim((string)SettingService::get($tenantId, 'shell_replace_explorer_path', ''));
        $shellReplaceArgs = trim((string)SettingService::get($tenantId, 'shell_replace_explorer_args', ''));

        $downloadLine = '$agentUrl = ""';
        if ($downloadUrl !== '') {
            $downloadLine = '$agentUrl = "' . addslashes($downloadUrl) . '"';
        }

        $shellLine = '$shellUrl = ""';
        if ($shellUrl !== '') {
            $shellLine = '$shellUrl = "' . addslashes($shellUrl) . '"';
        }

        $installArgs = str_replace(
            ['{SERVER}', '{PAIR_CODE}'],
            [$apiBase, $pairCode],
            $installArgs
        );
        if ($clientArgs === '') {
            $clientArgs = 'SERVER_URL="{SERVER}" PAIR_CODE="{PAIR_CODE}" AGENT_URL="{AGENT_URL}" SHELL_URL="{SHELL_URL}" AUTOSTART={AUTOSTART} AUTOSTART_PATH="{AUTOSTART_PATH}" AUTOSTART_ARGS="{AUTOSTART_ARGS}" AUTOSTART_SCOPE="{AUTOSTART_SCOPE}" REPLACE_SHELL={REPLACE_SHELL} REPLACE_SHELL_PATH="{REPLACE_SHELL_PATH}" REPLACE_SHELL_ARGS="{REPLACE_SHELL_ARGS}"';
        }
        $clientArgs = str_replace(
            ['{SERVER}', '{PAIR_CODE}', '{AGENT_URL}', '{SHELL_URL}', '{AUTOSTART}', '{AUTOSTART_PATH}', '{AUTOSTART_ARGS}', '{AUTOSTART_SCOPE}', '{REPLACE_SHELL}', '{REPLACE_SHELL_PATH}', '{REPLACE_SHELL_ARGS}'],
            [$apiBase, $pairCode, $downloadUrl, $shellUrl, $shellAutoStartEnabled, $shellAutoStartPath, $shellAutoStartArgs, $shellAutoStartScope, $shellReplaceEnabled, $shellReplacePath, $shellReplaceArgs],
            $clientArgs
        );
        $shellArgs = str_replace(
            ['{SERVER}', '{PAIR_CODE}'],
            [$apiBase, $pairCode],
            $shellArgs
        );

        return <<<PS1
\$ErrorActionPreference = "Stop"
[Net.ServicePointManager]::SecurityProtocol = [Net.SecurityProtocolType]::Tls12

\$server = "{$apiBase}"
\$pairCode = "{$pairCode}"
{$downloadLine}
\$shellArgs = '{$shellArgs}'
{$shellLine}
\$clientUrl = "{$clientUrl}"
\$clientArgs = '{$clientArgs}'
\$autoStartEnabled = '{$shellAutoStartEnabled}'
\$autoStartPath = "{$shellAutoStartPath}"
\$autoStartArgs = "{$shellAutoStartArgs}"
\$autoStartScope = "{$shellAutoStartScope}"
\$replaceShellEnabled = "{$shellReplaceEnabled}"
\$replaceShellPath = "{$shellReplacePath}"
\$replaceShellArgs = "{$shellReplaceArgs}"
\$installerArgs = '{$installArgs}'

Write-Host "MyCafeCloud quick install started..." -ForegroundColor Cyan
Write-Host "Server: \$server"
Write-Host "Pair code: \$pairCode"

if (\$clientUrl -ne "") {
    \$tmpc = Join-Path \$env:TEMP "mycafecloud-client-setup.exe"
    Write-Host "Downloading client from \$clientUrl ..." -ForegroundColor Yellow
    Invoke-WebRequest -Uri \$clientUrl -OutFile \$tmpc -UseBasicParsing
    Write-Host "Running client installer..." -ForegroundColor Yellow
    Start-Process -FilePath \$tmpc -ArgumentList \$clientArgs -Wait
} else {
    if (\$agentUrl -ne "") {
        \$tmp = Join-Path \$env:TEMP "mycafecloud-agent-setup.exe"
        Write-Host "Downloading agent from \$agentUrl ..." -ForegroundColor Yellow
        Invoke-WebRequest -Uri \$agentUrl -OutFile \$tmp -UseBasicParsing
        Write-Host "Running installer..." -ForegroundColor Yellow
        Start-Process -FilePath \$tmp -ArgumentList \$installerArgs -Wait
    }

    if (\$shellUrl -ne "") {
        \$tmp2 = Join-Path \$env:TEMP "mycafecloud-shell-setup.exe"
        Write-Host "Downloading shell from \$shellUrl ..." -ForegroundColor Yellow
        Invoke-WebRequest -Uri \$shellUrl -OutFile \$tmp2 -UseBasicParsing
        Write-Host "Running shell installer..." -ForegroundColor Yellow
        Start-Process -FilePath \$tmp2 -ArgumentList \$shellArgs -Wait
    }
}

if (\$autoStartEnabled -eq "1" -and \$autoStartPath -ne "") {
    try {
        \$cmd = ('"' + \$autoStartPath + '" ' + \$autoStartArgs).Trim()
        if (\$autoStartScope -eq "machine") {
            New-Item -Path "HKLM:\\Software\\Microsoft\\Windows\\CurrentVersion\\Run" -Force | Out-Null
            Set-ItemProperty -Path "HKLM:\\Software\\Microsoft\\Windows\\CurrentVersion\\Run" -Name "MyCafeCloudShell" -Value \$cmd
        } else {
            New-Item -Path "HKCU:\\Software\\Microsoft\\Windows\\CurrentVersion\\Run" -Force | Out-Null
            Set-ItemProperty -Path "HKCU:\\Software\\Microsoft\\Windows\\CurrentVersion\\Run" -Name "MyCafeCloudShell" -Value \$cmd
        }
        Write-Host "Shell autostart configured." -ForegroundColor Green
    } catch {
        Write-Host "Shell autostart failed: \$($_.Exception.Message)" -ForegroundColor Red
    }
}

if (\$replaceShellEnabled -eq "1" -and \$replaceShellPath -ne "") {
    try {
        \$shellCmd = ('"' + \$replaceShellPath + '" ' + \$replaceShellArgs).Trim()
        New-Item -Path "HKLM:\\SOFTWARE\\MyCafeCloud" -Force | Out-Null
        \$prev = (Get-ItemProperty -Path "HKLM:\\SOFTWARE\\Microsoft\\Windows NT\\CurrentVersion\\Winlogon" -Name "Shell" -ErrorAction SilentlyContinue).Shell
        if (\$prev) { Set-ItemProperty -Path "HKLM:\\SOFTWARE\\MyCafeCloud" -Name "PrevShell" -Value \$prev }
        Set-ItemProperty -Path "HKLM:\\SOFTWARE\\Microsoft\\Windows NT\\CurrentVersion\\Winlogon" -Name "Shell" -Value \$shellCmd
        Write-Host "Windows shell replaced." -ForegroundColor Yellow
    } catch {
        Write-Host "Shell replace failed: \$($_.Exception.Message)" -ForegroundColor Red
    }
}

\$payload = @{
    pair_code = \$pairCode
    pc_name   = \$env:COMPUTERNAME
} | ConvertTo-Json

try {
    \$resp = Invoke-RestMethod -Method Post -Uri "\$server/agent/pair" -ContentType "application/json" -Body \$payload
    Write-Host "Pair success: \$((\$resp.pc.code))" -ForegroundColor Green
} catch {
    Write-Host "Pair failed: \$($_.Exception.Message)" -ForegroundColor Red
    throw
}
PS1;
    }

    private function findValidPairCode(string $code): PcPairCode
    {
        $code = strtoupper(trim($code));
        if (!preg_match('/^[A-Z0-9]{4}-[A-Z0-9]{2}$/', $code)) {
            throw ValidationException::withMessages([
                'code' => 'Invalid pair code format.',
            ]);
        }

        $pair = PcPairCode::query()->where('code', $code)->firstOrFail();
        if ($pair->used_at) {
            throw ValidationException::withMessages([
                'code' => 'Pair code already used.',
            ]);
        }
        if ($pair->expires_at && $pair->expires_at->lte(now())) {
            throw ValidationException::withMessages([
                'code' => 'Pair code expired.',
            ]);
        }

        return $pair;
    }

    private function buildInstallOneLiner(string $scriptUrl): string
    {
        return 'powershell -NoProfile -ExecutionPolicy Bypass -Command "iwr -UseBasicParsing -Uri \'' . $scriptUrl . '\' | iex"';
    }

    private function buildGpoScript(string $scriptUrl): string
    {
        return <<<PS1
\$ErrorActionPreference = "Stop"
\$logDir = "C:\\ProgramData\\MyCafeCloud"
\$logFile = Join-Path \$logDir "gpo_install.log"
if (!(Test-Path \$logDir)) { New-Item -ItemType Directory -Path \$logDir | Out-Null }
function Log(\$msg) {
    \$line = ("[" + (Get-Date).ToString("s") + "] " + \$msg)
    Add-Content -Path \$logFile -Value \$line
}

try {
    \$svc = Get-Service -Name "MyCafeCloudAgent" -ErrorAction SilentlyContinue
    if (\$svc) {
        Log "Agent already installed. Exit."
        exit 0
    }
} catch {}

\$url = "{$scriptUrl}"
for (\$i = 1; \$i -le 3; \$i++) {
    try {
        Log "Running quick install attempt \$i..."
        iwr -UseBasicParsing -Uri \$url | iex
        Log "Quick install completed."
        exit 0
    } catch {
        Log "Attempt \$i failed: \$($_.Exception.Message)"
        Start-Sleep -Seconds 5
    }
}

throw "GPO install failed after 3 attempts."
PS1;
    }

    private function hasBatchIdColumn(): bool
    {
        return Schema::hasColumn('pc_commands', 'batch_id');
    }
}
