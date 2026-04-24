<?php

namespace App\Services;

use App\Enums\PcCommandType;
use App\Enums\PcStatus;
use App\Models\Operator;
use App\Models\Pc;
use App\Models\PcBooking;
use App\Models\PcCommand;
use App\Models\Session;
use App\Models\Zone;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class NexoraAssistantService
{
    private const PLAN_TTL_SECONDS = 600;

    public function __construct(
        private readonly NexoraIntentInterpreter $interpreter,
        private readonly PcCommandService $commands,
        private readonly EventLogger $events,
        private readonly TenantReportService $reports,
        private readonly TenantSettingService $settings,
    ) {
    }

    public function plan(int $tenantId, int $operatorId, string $message, string $locale = 'uz'): array
    {
        $intent = $this->interpreter->interpret($message);
        $metrics = $this->metrics($tenantId);

        return match ($intent['action']) {
            'shutdown_idle_pcs' => $this->planShutdownIdlePcs($tenantId, $operatorId, $message, $locale, $metrics),
            'lock_idle_pcs' => $this->planLockIdlePcs($tenantId, $operatorId, $message, $locale, $metrics),
            'reboot_named_pcs' => $this->planRebootNamedPcs($tenantId, $operatorId, $message, $locale, $metrics),
            'message_zone_pcs' => $this->planMessageZonePcs($tenantId, $operatorId, $message, $locale, $metrics),
            'today_revenue' => $this->planTodayRevenue($tenantId, $message, $locale, $metrics),
            'offline_pc_list' => $this->planOfflinePcList($tenantId, $message, $locale, $metrics),
            'idle_pc_list' => $this->planIdlePcList($tenantId, $message, $locale, $metrics),
            'hall_snapshot' => $this->planHallSnapshot($tenantId, $message, $locale, $metrics),
            default => $this->unsupportedPlan($message, $locale, $metrics),
        };
    }

    public function execute(int $tenantId, int $operatorId, string $planId, bool $confirmed, string $locale = 'uz'): array
    {
        $plan = $this->pullPlan($tenantId, $planId, $locale);

        if (($plan['confirmation_required'] ?? false) && !$confirmed) {
            throw ValidationException::withMessages([
                'confirmed' => $this->text($locale, 'confirmation_required'),
            ]);
        }

        return match ($plan['action'] ?? null) {
            'shutdown_idle_pcs' => $this->executeShutdownIdlePcs($tenantId, $operatorId, $plan, $locale),
            'lock_idle_pcs' => $this->executeLockIdlePcs($tenantId, $operatorId, $plan, $locale),
            'reboot_named_pcs' => $this->executeRebootNamedPcs($tenantId, $operatorId, $plan, $locale),
            'message_zone_pcs' => $this->executeMessageZonePcs($tenantId, $operatorId, $plan, $locale),
            default => throw ValidationException::withMessages([
                'plan_id' => $this->text($locale, 'plan_not_executable'),
            ]),
        };
    }

    public function overview(int $tenantId, string $locale = 'uz', bool $canManageAutopilot = false): array
    {
        $metrics = $this->metrics($tenantId);
        $autopilot = $this->autopilotSettings($tenantId);

        return [
            'summary' => [
                'title' => $this->text($locale, 'watch_summary_title'),
                'text' => $this->buildWatchSummary($locale, $metrics),
            ],
            'metrics' => $metrics,
            'alerts' => $this->watchAlerts($tenantId, $locale, $metrics, $autopilot),
            'suggested_actions' => $this->watchSuggestedActions($locale, $metrics, $autopilot),
            'autopilot' => [
                ...$autopilot,
                'can_manage' => $canManageAutopilot,
            ],
            'generated_at' => now()->toIso8601String(),
        ];
    }

    public function updateAutopilot(
        int $tenantId,
        int $operatorId,
        string $role,
        array $settings,
        string $locale = 'uz',
    ): array {
        if (!in_array($role, ['owner', 'admin'], true)) {
            throw ValidationException::withMessages([
                'autopilot' => $this->text($locale, 'autopilot_forbidden'),
            ]);
        }

        $payload = $this->normalizeAutopilotSettings($settings);
        $this->settings->set($tenantId, 'nexora_autopilot', $payload);

        $this->events->log(
            $tenantId,
            'nexora_autopilot_updated',
            'assistant',
            'operator',
            $operatorId,
            [
                'settings' => $payload,
            ],
        );

        return [
            'assistant_message' => $this->text($locale, 'autopilot_updated'),
            'autopilot' => [
                ...$payload,
                'can_manage' => true,
            ],
        ];
    }

    public function runAutopilotTick(): array
    {
        $processedTenants = 0;
        $executedCommands = 0;

        Operator::query()
            ->select(['tenant_id'])
            ->where('is_active', true)
            ->distinct()
            ->pluck('tenant_id')
            ->filter(static fn($tenantId) => is_numeric($tenantId))
            ->each(function ($tenantId) use (&$processedTenants, &$executedCommands): void {
                $tenantId = (int) $tenantId;
                $autopilot = $this->autopilotSettings($tenantId);
                if (!$autopilot['enabled'] || !$autopilot['auto_lock_idle_online']) {
                    return;
                }

                $targets = $this->collectTargets(
                    $this->idleCandidatesQuery($tenantId, [PcStatus::Online->value])
                        ->whereNotIn('id', function ($query) use ($tenantId) {
                            $query->select('pc_id')
                                ->from((new PcCommand())->getTable())
                                ->where('tenant_id', $tenantId)
                                ->where('type', PcCommandType::Lock->value)
                                ->whereIn('status', ['pending', 'sent'])
                                ->where('created_at', '>=', now()->subMinutes((int) config('domain.pc.lock_command_recent_window_minutes', 2)));
                        })
                        ->limit(25)
                        ->get()
                );

                if ($targets === []) {
                    return;
                }

                $processedTenants++;
                $batchId = 'nexora-auto-' . Str::lower((string) Str::uuid());
                $commandIds = [];

                foreach ($targets as $target) {
                    try {
                        $command = $this->commands->send(
                            $tenantId,
                            (int) $target['id'],
                            PcCommandType::Lock->value,
                            null,
                            $batchId,
                        );

                        $commandIds[] = (int) $command->id;
                    } catch (ValidationException) {
                        // State can change between polling rounds.
                    }
                }

                if ($commandIds === []) {
                    return;
                }

                $executedCommands += count($commandIds);
                $operatorId = $this->resolveAutopilotOperatorId($tenantId);

                $this->events->log(
                    $tenantId,
                    'nexora_autopilot_lock_executed',
                    'assistant',
                    'operator',
                    $operatorId,
                    [
                        'batch_id' => $batchId,
                        'command_ids' => $commandIds,
                        'target_ids' => array_map(static fn(array $target) => (int) $target['id'], $targets),
                        'action' => 'lock_idle_pcs',
                    ],
                );
            });

        return [
            'processed_tenants' => $processedTenants,
            'executed_commands' => $executedCommands,
        ];
    }

    private function planHallSnapshot(int $tenantId, string $message, string $locale, array $metrics): array
    {
        $targets = $this->collectTargets(
            $this->idleCandidatesQuery($tenantId)->limit(6)->get()
        );

        return [
            'plan_id' => null,
            'intent' => 'hall_snapshot',
            'action' => 'hall_snapshot',
            'action_label' => $this->text($locale, 'action_hall_snapshot'),
            'assistant_message' => $this->text($locale, 'hall_snapshot', [
                'online' => $metrics['online_pcs'],
                'active_sessions' => $metrics['active_sessions'],
                'idle' => $metrics['idle_online_pcs'],
                'codes' => $this->codesPreview($targets),
            ]),
            'confirmation_required' => false,
            'can_execute' => false,
            'target_count' => count($targets),
            'targets' => $targets,
            'metrics' => $metrics,
            'source' => 'rule_based',
            'original_message' => $message,
            'suggestions' => $this->suggestions($locale),
        ];
    }

    private function planIdlePcList(int $tenantId, string $message, string $locale, array $metrics): array
    {
        $targets = $this->collectTargets(
            $this->idleCandidatesQuery($tenantId)->limit(12)->get()
        );

        return [
            'plan_id' => null,
            'intent' => 'idle_pc_list',
            'action' => 'idle_pc_list',
            'action_label' => $this->text($locale, 'action_idle_pc_list'),
            'assistant_message' => count($targets) > 0
                ? $this->text($locale, 'idle_pc_list', ['codes' => $this->codesPreview($targets, 12)])
                : $this->text($locale, 'idle_pc_list_empty'),
            'confirmation_required' => false,
            'can_execute' => false,
            'target_count' => count($targets),
            'targets' => $targets,
            'metrics' => $metrics,
            'source' => 'rule_based',
            'original_message' => $message,
            'suggestions' => $this->suggestions($locale),
        ];
    }

    private function planShutdownIdlePcs(int $tenantId, int $operatorId, string $message, string $locale, array $metrics): array
    {
        $targets = $this->collectTargets(
            $this->idleCandidatesQuery($tenantId)->limit(20)->get()
        );

        if (count($targets) === 0) {
            return [
                'plan_id' => null,
                'intent' => 'shutdown_idle_pcs',
                'action' => 'shutdown_idle_pcs',
                'action_label' => $this->text($locale, 'action_shutdown_idle_pcs'),
                'assistant_message' => $this->text($locale, 'shutdown_idle_empty'),
                'confirmation_required' => false,
                'can_execute' => false,
                'target_count' => 0,
                'targets' => [],
                'metrics' => $metrics,
                'source' => 'rule_based',
                'original_message' => $message,
                'suggestions' => $this->suggestions($locale),
            ];
        }

        $planId = 'nexora-plan-' . Str::lower((string) Str::uuid());
        $payload = [
            'plan_id' => $planId,
            'tenant_id' => $tenantId,
            'operator_id' => $operatorId,
            'intent' => 'shutdown_idle_pcs',
            'action' => 'shutdown_idle_pcs',
            'action_label' => $this->text($locale, 'action_shutdown_idle_pcs'),
            'assistant_message' => $this->text($locale, 'shutdown_idle_plan', [
                'count' => count($targets),
                'codes' => $this->codesPreview($targets, 12),
            ]),
            'confirmation_required' => true,
            'can_execute' => true,
            'target_count' => count($targets),
            'target_ids' => array_map(static fn(array $target) => (int) $target['id'], $targets),
            'targets' => $targets,
            'metrics' => $metrics,
            'source' => 'rule_based',
            'original_message' => $message,
            'suggestions' => $this->suggestions($locale),
        ];

        Cache::put($this->planCacheKey($tenantId, $planId), $payload, now()->addSeconds(self::PLAN_TTL_SECONDS));

        $this->events->log(
            $tenantId,
            'nexora_plan_created',
            'assistant',
            'operator',
            $operatorId,
            [
                'plan_id' => $planId,
                'action' => 'shutdown_idle_pcs',
                'target_ids' => $payload['target_ids'],
                'message' => $message,
            ],
        );

        return $payload;
    }

    private function planLockIdlePcs(int $tenantId, int $operatorId, string $message, string $locale, array $metrics): array
    {
        $targets = $this->collectTargets(
            $this->idleCandidatesQuery($tenantId, [PcStatus::Online->value])->limit(20)->get()
        );

        return $this->createCommandPlan(
            $tenantId,
            $operatorId,
            $message,
            $locale,
            $metrics,
            'lock_idle_pcs',
            PcCommandType::Lock->value,
            $targets,
            $this->text($locale, 'action_lock_idle_pcs'),
            $this->text($locale, 'lock_idle_plan', [
                'count' => count($targets),
                'codes' => $this->codesPreview($targets, 12),
            ]),
            $this->text($locale, 'lock_idle_empty'),
        );
    }

    private function planRebootNamedPcs(int $tenantId, int $operatorId, string $message, string $locale, array $metrics): array
    {
        $targets = $this->mentionedPcTargets($tenantId, $message);

        if (count($targets) === 0) {
            return $this->nonExecutablePlan(
                'reboot_named_pcs',
                $this->text($locale, 'action_reboot_named_pcs'),
                $this->text($locale, 'reboot_need_pc_codes'),
                $message,
                $locale,
                $metrics,
            );
        }

        return $this->createCommandPlan(
            $tenantId,
            $operatorId,
            $message,
            $locale,
            $metrics,
            'reboot_named_pcs',
            PcCommandType::Reboot->value,
            $targets,
            $this->text($locale, 'action_reboot_named_pcs'),
            $this->text($locale, 'reboot_plan', [
                'count' => count($targets),
                'codes' => $this->codesPreview($targets, 12),
            ]),
            $this->text($locale, 'reboot_need_pc_codes'),
        );
    }

    private function planMessageZonePcs(int $tenantId, int $operatorId, string $message, string $locale, array $metrics): array
    {
        $zoneName = $this->detectZoneName($tenantId, $message);
        $messageText = $this->extractQuotedText($message);

        if (!$zoneName) {
            return $this->nonExecutablePlan(
                'message_zone_pcs',
                $this->text($locale, 'action_message_zone_pcs'),
                $this->text($locale, 'message_zone_need_zone'),
                $message,
                $locale,
                $metrics,
            );
        }

        if (!$messageText) {
            return $this->nonExecutablePlan(
                'message_zone_pcs',
                $this->text($locale, 'action_message_zone_pcs'),
                $this->text($locale, 'message_zone_need_text'),
                $message,
                $locale,
                $metrics,
            );
        }

        $targets = $this->collectTargets(
            $this->zoneOnlineCandidatesQuery($tenantId, $zoneName)->limit(50)->get()
        );

        return $this->createCommandPlan(
            $tenantId,
            $operatorId,
            $message,
            $locale,
            $metrics,
            'message_zone_pcs',
            PcCommandType::Message->value,
            $targets,
            $this->text($locale, 'action_message_zone_pcs'),
            $this->text($locale, 'message_zone_plan', [
                'zone' => $zoneName,
                'count' => count($targets),
                'text' => $messageText,
            ]),
            $this->text($locale, 'message_zone_empty', ['zone' => $zoneName]),
            ['text' => $messageText, 'zone' => $zoneName],
        );
    }

    private function planTodayRevenue(int $tenantId, string $message, string $locale, array $metrics): array
    {
        $from = Carbon::today();
        $to = Carbon::today()->endOfDay();
        $report = $this->reports->build($tenantId, $from, $to);
        $summary = (array) ($report['summary'] ?? []);

        return [
            'plan_id' => null,
            'intent' => 'today_revenue',
            'action' => 'today_revenue',
            'action_label' => $this->text($locale, 'action_today_revenue'),
            'assistant_message' => $this->text($locale, 'today_revenue', [
                'gross' => (int) ($summary['gross_sales'] ?? 0),
                'net' => (int) ($summary['net_sales'] ?? 0),
                'sessions' => (int) ($summary['sessions_count'] ?? 0),
            ]),
            'confirmation_required' => false,
            'can_execute' => false,
            'target_count' => 0,
            'targets' => [],
            'metrics' => $metrics,
            'source' => 'rule_based',
            'original_message' => $message,
            'suggestions' => $this->suggestions($locale),
        ];
    }

    private function planOfflinePcList(int $tenantId, string $message, string $locale, array $metrics): array
    {
        $targets = $this->collectTargets(
            $this->offlineCandidatesQuery($tenantId)->limit(12)->get()
        );

        return [
            'plan_id' => null,
            'intent' => 'offline_pc_list',
            'action' => 'offline_pc_list',
            'action_label' => $this->text($locale, 'action_offline_pc_list'),
            'assistant_message' => count($targets) > 0
                ? $this->text($locale, 'offline_pc_list', ['codes' => $this->codesPreview($targets, 12)])
                : $this->text($locale, 'offline_pc_list_empty'),
            'confirmation_required' => false,
            'can_execute' => false,
            'target_count' => count($targets),
            'targets' => $targets,
            'metrics' => $metrics,
            'source' => 'rule_based',
            'original_message' => $message,
            'suggestions' => $this->suggestions($locale),
        ];
    }

    private function executeShutdownIdlePcs(int $tenantId, int $operatorId, array $plan, string $locale): array
    {
        $targetIds = array_values(array_filter(
            array_map(static fn($id) => (int) $id, $plan['target_ids'] ?? []),
            static fn(int $id) => $id > 0,
        ));

        $candidates = $this->idleCandidatesQuery($tenantId)
            ->whereIn('id', $targetIds)
            ->get();

        $targets = $this->collectTargets($candidates);

        if (count($targets) === 0) {
            return [
                'status' => 'noop',
                'action' => 'shutdown_idle_pcs',
                'action_label' => $this->text($locale, 'action_shutdown_idle_pcs'),
                'assistant_message' => $this->text($locale, 'shutdown_idle_empty'),
                'batch_id' => null,
                'executed_count' => 0,
                'skipped_count' => count($targetIds),
                'command_ids' => [],
                'targets' => [],
            ];
        }

        $batchId = 'nexora-' . Str::lower((string) Str::uuid());
        $commandIds = [];
        foreach ($targets as $target) {
            try {
                $command = $this->commands->send(
                    $tenantId,
                    (int) $target['id'],
                    PcCommandType::Shutdown->value,
                    null,
                    $batchId,
                );

                $commandIds[] = (int) $command->id;
            } catch (ValidationException) {
                // Re-check can still fail if the PC state changed after planning.
            }
        }

        Cache::forget($this->planCacheKey($tenantId, (string) $plan['plan_id']));

        $executedCount = count($commandIds);
        $skippedCount = max(0, count($targetIds) - $executedCount);

        $this->events->log(
            $tenantId,
            'nexora_command_batch_executed',
            'assistant',
            'operator',
            $operatorId,
            [
                'plan_id' => $plan['plan_id'] ?? null,
                'action' => 'shutdown_idle_pcs',
                'batch_id' => $executedCount > 0 ? $batchId : null,
                'command_ids' => $commandIds,
                'target_ids' => array_map(static fn(array $target) => (int) $target['id'], $targets),
                'original_message' => $plan['original_message'] ?? null,
                'executed_count' => $executedCount,
                'skipped_count' => $skippedCount,
            ],
        );

        return [
            'status' => $executedCount > 0 ? 'executed' : 'noop',
            'action' => 'shutdown_idle_pcs',
            'action_label' => $this->text($locale, 'action_shutdown_idle_pcs'),
            'assistant_message' => $executedCount > 0
                ? $this->text($locale, 'shutdown_idle_executed', ['count' => $executedCount])
                : $this->text($locale, 'shutdown_idle_empty'),
            'batch_id' => $executedCount > 0 ? $batchId : null,
            'executed_count' => $executedCount,
            'skipped_count' => $skippedCount,
            'command_ids' => $commandIds,
            'targets' => $targets,
        ];
    }

    private function executeLockIdlePcs(int $tenantId, int $operatorId, array $plan, string $locale): array
    {
        $targetIds = $this->planTargetIds($plan);
        $targets = $this->collectTargets(
            $this->idleCandidatesQuery($tenantId, [PcStatus::Online->value])
                ->whereIn('id', $targetIds)
                ->get()
        );

        return $this->executePcCommandTargets(
            $tenantId,
            $operatorId,
            $plan,
            $locale,
            $targets,
            PcCommandType::Lock->value,
            $this->text($locale, 'action_lock_idle_pcs'),
            $this->text($locale, 'lock_idle_empty'),
            $this->text($locale, 'lock_idle_executed', ['count' => count($targets)]),
        );
    }

    private function executeRebootNamedPcs(int $tenantId, int $operatorId, array $plan, string $locale): array
    {
        $targetIds = $this->planTargetIds($plan);
        $targets = $this->collectTargets(
            $this->onlineCommandCandidatesQuery($tenantId)
                ->whereIn('id', $targetIds)
                ->get()
        );

        return $this->executePcCommandTargets(
            $tenantId,
            $operatorId,
            $plan,
            $locale,
            $targets,
            PcCommandType::Reboot->value,
            $this->text($locale, 'action_reboot_named_pcs'),
            $this->text($locale, 'reboot_empty'),
            $this->text($locale, 'reboot_executed', ['count' => count($targets)]),
        );
    }

    private function executeMessageZonePcs(int $tenantId, int $operatorId, array $plan, string $locale): array
    {
        $messageText = (string) data_get($plan, 'command_payload.text', '');
        $zoneName = (string) data_get($plan, 'command_payload.zone', '');
        $targets = $this->collectTargets(
            $this->zoneOnlineCandidatesQuery($tenantId, $zoneName)
                ->whereIn('id', $this->planTargetIds($plan))
                ->get()
        );

        return $this->executePcCommandTargets(
            $tenantId,
            $operatorId,
            $plan,
            $locale,
            $targets,
            PcCommandType::Message->value,
            $this->text($locale, 'action_message_zone_pcs'),
            $this->text($locale, 'message_zone_empty', ['zone' => $zoneName]),
            $this->text($locale, 'message_zone_executed', ['count' => count($targets)]),
            ['text' => $messageText],
        );
    }

    private function unsupportedPlan(string $message, string $locale, array $metrics): array
    {
        return [
            'plan_id' => null,
            'intent' => 'unsupported',
            'action' => 'unsupported',
            'action_label' => $this->text($locale, 'action_unsupported'),
            'assistant_message' => $this->text($locale, 'unsupported'),
            'confirmation_required' => false,
            'can_execute' => false,
            'target_count' => 0,
            'targets' => [],
            'metrics' => $metrics,
            'source' => 'rule_based',
            'original_message' => $message,
            'suggestions' => $this->suggestions($locale),
        ];
    }

    private function nonExecutablePlan(
        string $action,
        string $actionLabel,
        string $assistantMessage,
        string $message,
        string $locale,
        array $metrics
    ): array {
        return [
            'plan_id' => null,
            'intent' => $action,
            'action' => $action,
            'action_label' => $actionLabel,
            'assistant_message' => $assistantMessage,
            'confirmation_required' => false,
            'can_execute' => false,
            'target_count' => 0,
            'targets' => [],
            'metrics' => $metrics,
            'source' => 'rule_based',
            'original_message' => $message,
            'suggestions' => $this->suggestions($locale),
        ];
    }

    private function createCommandPlan(
        int $tenantId,
        int $operatorId,
        string $message,
        string $locale,
        array $metrics,
        string $action,
        string $commandType,
        array $targets,
        string $actionLabel,
        string $assistantMessage,
        string $emptyMessage,
        ?array $commandPayload = null
    ): array {
        if (count($targets) === 0) {
            return $this->nonExecutablePlan(
                $action,
                $actionLabel,
                $emptyMessage,
                $message,
                $locale,
                $metrics,
            );
        }

        $planId = 'nexora-plan-' . Str::lower((string) Str::uuid());
        $payload = [
            'plan_id' => $planId,
            'tenant_id' => $tenantId,
            'operator_id' => $operatorId,
            'intent' => $action,
            'action' => $action,
            'action_label' => $actionLabel,
            'assistant_message' => $assistantMessage,
            'confirmation_required' => true,
            'can_execute' => true,
            'target_count' => count($targets),
            'target_ids' => array_map(static fn(array $target) => (int) $target['id'], $targets),
            'targets' => $targets,
            'command_type' => $commandType,
            'command_payload' => $commandPayload,
            'metrics' => $metrics,
            'source' => 'rule_based',
            'original_message' => $message,
            'suggestions' => $this->suggestions($locale),
        ];

        Cache::put($this->planCacheKey($tenantId, $planId), $payload, now()->addSeconds(self::PLAN_TTL_SECONDS));

        $this->events->log(
            $tenantId,
            'nexora_plan_created',
            'assistant',
            'operator',
            $operatorId,
            [
                'plan_id' => $planId,
                'action' => $action,
                'command_type' => $commandType,
                'target_ids' => $payload['target_ids'],
                'message' => $message,
                'command_payload' => $commandPayload,
            ],
        );

        return $payload;
    }

    private function executePcCommandTargets(
        int $tenantId,
        int $operatorId,
        array $plan,
        string $locale,
        array $targets,
        string $commandType,
        string $actionLabel,
        string $emptyMessage,
        string $successMessage,
        ?array $payload = null
    ): array {
        $targetIds = $this->planTargetIds($plan);

        if (count($targets) === 0) {
            return [
                'status' => 'noop',
                'action' => (string) ($plan['action'] ?? 'unsupported'),
                'action_label' => $actionLabel,
                'assistant_message' => $emptyMessage,
                'batch_id' => null,
                'executed_count' => 0,
                'skipped_count' => count($targetIds),
                'command_ids' => [],
                'targets' => [],
            ];
        }

        $batchId = 'nexora-' . Str::lower((string) Str::uuid());
        $commandIds = [];

        foreach ($targets as $target) {
            try {
                $command = $this->commands->send(
                    $tenantId,
                    (int) $target['id'],
                    $commandType,
                    $payload,
                    $batchId,
                );

                $commandIds[] = (int) $command->id;
            } catch (ValidationException) {
                // State may change between planning and execution.
            }
        }

        Cache::forget($this->planCacheKey($tenantId, (string) $plan['plan_id']));

        $executedCount = count($commandIds);
        $skippedCount = max(0, count($targetIds) - $executedCount);

        $this->events->log(
            $tenantId,
            'nexora_command_batch_executed',
            'assistant',
            'operator',
            $operatorId,
            [
                'plan_id' => $plan['plan_id'] ?? null,
                'action' => $plan['action'] ?? null,
                'command_type' => $commandType,
                'batch_id' => $executedCount > 0 ? $batchId : null,
                'command_ids' => $commandIds,
                'target_ids' => array_map(static fn(array $target) => (int) $target['id'], $targets),
                'original_message' => $plan['original_message'] ?? null,
                'executed_count' => $executedCount,
                'skipped_count' => $skippedCount,
                'payload' => $payload,
            ],
        );

        return [
            'status' => $executedCount > 0 ? 'executed' : 'noop',
            'action' => (string) ($plan['action'] ?? 'unsupported'),
            'action_label' => $actionLabel,
            'assistant_message' => $executedCount > 0 ? $successMessage : $emptyMessage,
            'batch_id' => $executedCount > 0 ? $batchId : null,
            'executed_count' => $executedCount,
            'skipped_count' => $skippedCount,
            'command_ids' => $commandIds,
            'targets' => $targets,
        ];
    }

    private function pullPlan(int $tenantId, string $planId, string $locale): array
    {
        $plan = Cache::get($this->planCacheKey($tenantId, $planId));

        if (!is_array($plan)) {
            throw ValidationException::withMessages([
                'plan_id' => $this->text($locale, 'plan_not_found'),
            ]);
        }

        return $plan;
    }

    private function planCacheKey(int $tenantId, string $planId): string
    {
        return 'nexora:plan:' . $tenantId . ':' . $planId;
    }

    private function idleCandidatesQuery(int $tenantId, ?array $statuses = null): Builder
    {
        $onlineSince = now()->subMinutes((int) config('domain.pc.online_window_minutes', 3));

        return Pc::query()
            ->where('tenant_id', $tenantId)
            ->where('is_hidden', false)
            ->whereIn('status', $statuses ?: [
                PcStatus::Online->value,
                PcStatus::Locked->value,
            ])
            ->whereNotNull('last_seen_at')
            ->where('last_seen_at', '>=', $onlineSince)
            ->whereDoesntHave('sessions', function (Builder $query): void {
                $query->where('status', 'active');
            })
            ->whereNotIn('id', function ($query) use ($tenantId) {
                $query->select('pc_id')
                    ->from((new PcBooking())->getTable())
                    ->where('tenant_id', $tenantId)
                    ->where('reserved_until', '>', now());
            })
            ->orderBy('code');
    }

    private function onlineCommandCandidatesQuery(int $tenantId): Builder
    {
        $onlineSince = now()->subMinutes((int) config('domain.pc.online_window_minutes', 3));

        return Pc::query()
            ->where('tenant_id', $tenantId)
            ->where('is_hidden', false)
            ->whereIn('status', PcStatus::onlineValues())
            ->whereNotNull('last_seen_at')
            ->where('last_seen_at', '>=', $onlineSince)
            ->whereDoesntHave('sessions', function (Builder $query): void {
                $query->where('status', 'active');
            })
            ->whereNotIn('id', function ($query) use ($tenantId) {
                $query->select('pc_id')
                    ->from((new PcBooking())->getTable())
                    ->where('tenant_id', $tenantId)
                    ->where('reserved_until', '>', now());
            })
            ->orderBy('code');
    }

    private function zoneOnlineCandidatesQuery(int $tenantId, string $zoneName): Builder
    {
        $onlineSince = now()->subMinutes((int) config('domain.pc.online_window_minutes', 3));

        return Pc::query()
            ->where('tenant_id', $tenantId)
            ->where('is_hidden', false)
            ->where(function (Builder $query) use ($zoneName): void {
                $query
                    ->where('zone', $zoneName)
                    ->orWhereHas('zoneRel', function (Builder $zoneQuery) use ($zoneName): void {
                        $zoneQuery->where('name', $zoneName);
                    });
            })
            ->whereIn('status', PcStatus::onlineValues())
            ->whereNotNull('last_seen_at')
            ->where('last_seen_at', '>=', $onlineSince)
            ->orderBy('code');
    }

    private function offlineCandidatesQuery(int $tenantId): Builder
    {
        $onlineSince = now()->subMinutes((int) config('domain.pc.online_window_minutes', 3));

        return Pc::query()
            ->where('tenant_id', $tenantId)
            ->where('is_hidden', false)
            ->where(function (Builder $query) use ($onlineSince): void {
                $query
                    ->where('status', PcStatus::Offline->value)
                    ->orWhereNull('last_seen_at')
                    ->orWhere('last_seen_at', '<', $onlineSince);
            })
            ->orderBy('code');
    }

    private function mentionedPcTargets(int $tenantId, string $message): array
    {
        $normalized = mb_strtolower($message);

        $pcs = Pc::query()
            ->where('tenant_id', $tenantId)
            ->where('is_hidden', false)
            ->orderBy('code')
            ->get();

        $matched = $pcs->filter(function (Pc $pc) use ($normalized): bool {
            $code = mb_strtolower((string) $pc->code);

            return $code !== '' && str_contains($normalized, $code);
        });

        return $this->collectTargets($matched->values());
    }

    private function detectZoneName(int $tenantId, string $message): ?string
    {
        $normalized = mb_strtolower($message);

        $zones = Zone::query()
            ->where('tenant_id', $tenantId)
            ->orderBy('name')
            ->get(['name']);

        foreach ($zones as $zone) {
            $name = trim((string) $zone->name);
            if ($name !== '' && str_contains($normalized, mb_strtolower($name))) {
                return $name;
            }
        }

        return null;
    }

    private function extractQuotedText(string $message): ?string
    {
        if (preg_match('/["“](.+?)["”]/u', $message, $matches) === 1) {
            $text = trim((string) ($matches[1] ?? ''));

            return $text !== '' ? $text : null;
        }

        if (preg_match("/'([^']+)'/u", $message, $matches) === 1) {
            $text = trim((string) ($matches[1] ?? ''));

            return $text !== '' ? $text : null;
        }

        return null;
    }

    private function planTargetIds(array $plan): array
    {
        return array_values(array_filter(
            array_map(static fn($id) => (int) $id, $plan['target_ids'] ?? []),
            static fn(int $id) => $id > 0,
        ));
    }

    private function metrics(int $tenantId): array
    {
        $totalPcs = Pc::query()
            ->where('tenant_id', $tenantId)
            ->where('is_hidden', false)
            ->count();

        $onlineSince = now()->subMinutes((int) config('domain.pc.online_window_minutes', 3));

        $onlinePcs = Pc::query()
            ->where('tenant_id', $tenantId)
            ->where('is_hidden', false)
            ->whereIn('status', PcStatus::onlineValues())
            ->whereNotNull('last_seen_at')
            ->where('last_seen_at', '>=', $onlineSince)
            ->count();

        $activeSessions = Session::query()
            ->where('tenant_id', $tenantId)
            ->where('status', 'active')
            ->count();

        $bookedPcs = PcBooking::query()
            ->where('tenant_id', $tenantId)
            ->where('reserved_until', '>', now())
            ->distinct()
            ->count('pc_id');

        $idleOnlinePcs = $this->idleCandidatesQuery($tenantId)->count();
        $offlinePcs = $this->offlineCandidatesQuery($tenantId)->count();

        return [
            'total_pcs' => $totalPcs,
            'online_pcs' => $onlinePcs,
            'offline_pcs' => $offlinePcs,
            'active_sessions' => $activeSessions,
            'booked_pcs' => $bookedPcs,
            'idle_online_pcs' => $idleOnlinePcs,
        ];
    }

    private function autopilotSettings(int $tenantId): array
    {
        return $this->normalizeAutopilotSettings(
            $this->settings->get($tenantId, 'nexora_autopilot', [])
        );
    }

    private function normalizeAutopilotSettings(mixed $value): array
    {
        $defaults = (array) config('domain.nexora.autopilot', []);
        $value = is_array($value) ? $value : [];

        return [
            'enabled' => (bool) ($value['enabled'] ?? $defaults['enabled'] ?? false),
            'auto_lock_idle_online' => (bool) ($value['auto_lock_idle_online'] ?? $defaults['auto_lock_idle_online'] ?? false),
            'suggest_idle_shutdown' => (bool) ($value['suggest_idle_shutdown'] ?? $defaults['suggest_idle_shutdown'] ?? true),
            'suggest_offline_watch' => (bool) ($value['suggest_offline_watch'] ?? $defaults['suggest_offline_watch'] ?? true),
        ];
    }

    private function buildWatchSummary(string $locale, array $metrics): string
    {
        $total = max(1, (int) ($metrics['total_pcs'] ?? 0));
        $loadRatio = (int) round((((int) ($metrics['active_sessions'] ?? 0)) / $total) * 100);

        return $this->text($locale, 'watch_summary_text', [
            'online' => (int) ($metrics['online_pcs'] ?? 0),
            'idle' => (int) ($metrics['idle_online_pcs'] ?? 0),
            'offline' => (int) ($metrics['offline_pcs'] ?? 0),
            'load' => $loadRatio,
        ]);
    }

    private function watchAlerts(int $tenantId, string $locale, array $metrics, array $autopilot): array
    {
        $alerts = [];
        $idleCount = (int) ($metrics['idle_online_pcs'] ?? 0);
        $offlineCount = (int) ($metrics['offline_pcs'] ?? 0);
        $activeSessions = (int) ($metrics['active_sessions'] ?? 0);
        $onlineCount = (int) ($metrics['online_pcs'] ?? 0);
        $total = max(1, (int) ($metrics['total_pcs'] ?? 0));
        $loadRatio = $activeSessions / $total;
        $watchConfig = (array) config('domain.nexora.watch', []);

        if ($autopilot['suggest_idle_shutdown'] && $idleCount >= (int) ($watchConfig['idle_alert_count'] ?? 3)) {
            $targets = $this->collectTargets(
                $this->idleCandidatesQuery($tenantId)->limit(6)->get()
            );

            $alerts[] = [
                'id' => 'idle-pcs',
                'severity' => 'warning',
                'title' => $this->text($locale, 'alert_idle_title'),
                'body' => $this->text($locale, 'alert_idle_body', [
                    'count' => $idleCount,
                    'codes' => $this->codesPreview($targets, 6),
                ]),
                'prompt' => $this->text($locale, 'prompt_lock_idle_pcs'),
                'action_label' => $this->text($locale, 'action_lock_idle_pcs'),
                'target_count' => $idleCount,
            ];
        }

        if ($autopilot['suggest_offline_watch'] && $offlineCount >= (int) ($watchConfig['offline_alert_count'] ?? 1)) {
            $targets = $this->collectTargets(
                $this->offlineCandidatesQuery($tenantId)->limit(6)->get()
            );

            $alerts[] = [
                'id' => 'offline-pcs',
                'severity' => $offlineCount >= 3 ? 'critical' : 'info',
                'title' => $this->text($locale, 'alert_offline_title'),
                'body' => $this->text($locale, 'alert_offline_body', [
                    'count' => $offlineCount,
                    'codes' => $this->codesPreview($targets, 6),
                ]),
                'prompt' => $this->text($locale, 'prompt_show_offline'),
                'action_label' => $this->text($locale, 'action_offline_pc_list'),
                'target_count' => $offlineCount,
            ];
        }

        if ($onlineCount > 0 && $loadRatio <= (float) ($watchConfig['low_load_ratio'] ?? 0.25)) {
            $alerts[] = [
                'id' => 'low-load',
                'severity' => 'soft',
                'title' => $this->text($locale, 'alert_load_title'),
                'body' => $this->text($locale, 'alert_load_body', [
                    'active' => $activeSessions,
                    'online' => $onlineCount,
                ]),
                'prompt' => $this->text($locale, 'prompt_hall_snapshot'),
                'action_label' => $this->text($locale, 'action_hall_snapshot'),
                'target_count' => 0,
            ];
        }

        return array_slice($alerts, 0, 3);
    }

    private function watchSuggestedActions(string $locale, array $metrics, array $autopilot): array
    {
        $actions = [];

        if ((int) ($metrics['idle_online_pcs'] ?? 0) > 0) {
            $actions[] = [
                'id' => 'lock-idle',
                'label' => $this->text($locale, 'watch_action_lock_idle_title'),
                'body' => $this->text($locale, 'watch_action_lock_idle_body'),
                'prompt' => $this->text($locale, 'prompt_lock_idle_pcs'),
                'requires_confirmation' => true,
                'kind' => 'safe',
            ];
        }

        if ((int) ($metrics['offline_pcs'] ?? 0) > 0) {
            $actions[] = [
                'id' => 'show-offline',
                'label' => $this->text($locale, 'watch_action_offline_title'),
                'body' => $this->text($locale, 'watch_action_offline_body'),
                'prompt' => $this->text($locale, 'prompt_show_offline'),
                'requires_confirmation' => false,
                'kind' => 'observe',
            ];
        }

        if ((int) ($metrics['idle_online_pcs'] ?? 0) > 0 && $autopilot['suggest_idle_shutdown']) {
            $actions[] = [
                'id' => 'shutdown-idle',
                'label' => $this->text($locale, 'watch_action_shutdown_idle_title'),
                'body' => $this->text($locale, 'watch_action_shutdown_idle_body'),
                'prompt' => $this->text($locale, 'prompt_shutdown_idle_pcs'),
                'requires_confirmation' => true,
                'kind' => 'risky',
            ];
        }

        $actions[] = [
            'id' => 'today-revenue',
            'label' => $this->text($locale, 'watch_action_revenue_title'),
            'body' => $this->text($locale, 'watch_action_revenue_body'),
            'prompt' => $this->text($locale, 'prompt_today_revenue'),
            'requires_confirmation' => false,
            'kind' => 'observe',
        ];

        return array_slice($actions, 0, 4);
    }

    private function collectTargets(Collection $pcs): array
    {
        return $pcs
            ->map(fn(Pc $pc) => [
                'id' => (int) $pc->id,
                'code' => (string) $pc->code,
                'zone' => (string) ($pc->zone ?: $pc->zoneRel?->name ?: '-'),
                'status' => (string) $pc->status,
                'last_seen_at' => $pc->last_seen_at?->toIso8601String(),
            ])
            ->values()
            ->all();
    }

    private function codesPreview(array $targets, int $limit = 6): string
    {
        $codes = array_values(array_filter(
            array_map(static fn(array $target) => (string) ($target['code'] ?? ''), $targets),
            static fn(string $code) => $code !== '',
        ));

        if ($codes === []) {
            return '-';
        }

        $chunk = array_slice($codes, 0, $limit);
        $suffix = count($codes) > $limit ? ', ...' : '';

        return implode(', ', $chunk) . $suffix;
    }

    private function resolveAutopilotOperatorId(int $tenantId): ?int
    {
        $ownerOrAdmin = Operator::query()
            ->where('tenant_id', $tenantId)
            ->where('is_active', true)
            ->whereIn('role', ['owner', 'admin'])
            ->orderByRaw("CASE WHEN role = 'owner' THEN 0 ELSE 1 END")
            ->orderBy('id')
            ->value('id');

        if ($ownerOrAdmin) {
            return (int) $ownerOrAdmin;
        }

        $any = Operator::query()
            ->where('tenant_id', $tenantId)
            ->where('is_active', true)
            ->orderBy('id')
            ->value('id');

        return $any ? (int) $any : null;
    }

    private function suggestions(string $locale): array
    {
        return match ($locale) {
            'ru' => [
                'Сколько сейчас свободных ПК?',
                'Покажи свободные и включенные компьютеры.',
                'Выключи все включенные компьютеры без людей.',
                'Покажи офлайн компьютеры.',
                'Какая выручка сегодня?',
                'Перезагрузи PC-1 и PC-2.',
            ],
            'en' => [
                'How many free PCs are online right now?',
                'Show idle powered-on computers.',
                'Shut down all powered-on PCs without active users.',
                'Show offline computers.',
                'What is today revenue?',
                'Reboot PC-1 and PC-2.',
            ],
            default => [
                'Hozir nechta bo‘sh PC bor?',
                'Bo‘sh va yoniq kompyuterlarni ko‘rsat.',
                'Barcha yoniq va odam yo‘q kompyuterlarni o‘chir.',
                'Offline kompyuterlarni ko‘rsat.',
                'Bugungi tushum qancha?',
                'PC-1 va PC-2 ni reboot qil.',
            ],
        };
    }

    private function text(string $locale, string $key, array $replace = []): string
    {
        $dictionary = [
            'uz' => [
                'action_hall_snapshot' => 'Zal holati',
                'action_idle_pc_list' => 'Bo‘sh PClar',
                'action_shutdown_idle_pcs' => 'Bo‘sh PClarni o‘chirish',
                'action_lock_idle_pcs' => 'Bo‘sh PClarni lock qilish',
                'action_reboot_named_pcs' => 'PC reboot',
                'action_message_zone_pcs' => 'Zona bo‘yicha xabar',
                'action_today_revenue' => 'Bugungi tushum',
                'action_offline_pc_list' => 'Offline PClar',
                'action_unsupported' => 'Yordam',
                'hall_snapshot' => 'Hozir zalda :online ta online PC, :active_sessions ta aktiv sessiya va :idle ta bo‘sh online PC bor. Eng yaqin bo‘shlar: :codes.',
                'idle_pc_list' => 'Hozir bo‘sh va yoniq PClar: :codes.',
                'idle_pc_list_empty' => 'Hozir bo‘sh va yoniq PC topilmadi.',
                'shutdown_idle_plan' => 'Men :count ta bo‘sh yoniq PC topdim: :codes. Tasdiqlasangiz shutdown buyrug‘ini yuboraman.',
                'shutdown_idle_empty' => 'Hozir shutdown uchun mos bo‘sh PC topilmadi.',
                'shutdown_idle_executed' => ':count ta PC ga shutdown buyrug‘i yuborildi.',
                'lock_idle_plan' => 'Men :count ta bo‘sh online PC topdim: :codes. Tasdiqlasangiz lock buyrug‘ini yuboraman.',
                'lock_idle_empty' => 'Lock qilish uchun mos bo‘sh online PC topilmadi.',
                'lock_idle_executed' => ':count ta PC ga lock buyrug‘i yuborildi.',
                'reboot_need_pc_codes' => 'Qaysi PC larni reboot qilishni yozing. Masalan: PC-1 va PC-2 ni reboot qil.',
                'reboot_plan' => 'Men quyidagi PC larni reboot qilishga tayyorman: :codes. Tasdiqlasangiz reboot buyrug‘i yuboraman.',
                'reboot_empty' => 'Reboot uchun hozir mos PC topilmadi.',
                'reboot_executed' => ':count ta PC ga reboot buyrug‘i yuborildi.',
                'message_zone_need_zone' => 'Qaysi zonaga xabar yuborishni yozing. Masalan: VIP zonaga "10 daqiqada turnir boshlanadi" deb yubor.',
                'message_zone_need_text' => 'Xabar matnini qo‘shtirnoq ichida yozing. Masalan: VIP zonaga "10 daqiqada turnir boshlanadi" deb yubor.',
                'message_zone_plan' => 'Men :zone zonadagi :count ta PC ga quyidagi xabarni yuboraman: ":text". Tasdiqlaysizmi?',
                'message_zone_empty' => ':zone zonada online PC topilmadi.',
                'message_zone_executed' => ':count ta PC ga xabar yuborildi.',
                'today_revenue' => 'Bugun gross tushum :gross UZS, net tushum :net UZS. Sessiyalar soni: :sessions.',
                'offline_pc_list' => 'Hozir offline yoki uzilib qolgan PClar: :codes.',
                'offline_pc_list_empty' => 'Hozir offline PC topilmadi.',
                'watch_summary_title' => 'Nexora Watch',
                'watch_summary_text' => 'Hozir :online ta online PC, :idle ta bo‘sh online PC va :offline ta offline PC bor. Joriy yuklama taxminan :load%.',
                'alert_idle_title' => 'Bo‘sh va yoniq PClar ko‘paydi',
                'alert_idle_body' => ':count ta PC bo‘sh turibdi. Eng yaqinlari: :codes.',
                'alert_offline_title' => 'Offline PC lar bor',
                'alert_offline_body' => ':count ta PC offline yoki uzilib qolgan. Eng muhimlari: :codes.',
                'alert_load_title' => 'Zal yuklamasi past',
                'alert_load_body' => 'Hozir :online ta online PC dan faqat :active ta sessiya ishlayapti. Nexora holatni ko‘rib chiqishni tavsiya qiladi.',
                'prompt_lock_idle_pcs' => 'Bo‘sh va yoniq kompyuterlarni lock qil.',
                'prompt_show_offline' => 'Offline kompyuterlarni ko‘rsat.',
                'prompt_hall_snapshot' => 'Hozir zal holati qanday?',
                'prompt_shutdown_idle_pcs' => 'Barcha yoniq va odam yo‘q kompyuterlarni o‘chir.',
                'prompt_today_revenue' => 'Bugungi tushum qancha?',
                'watch_action_lock_idle_title' => 'Bo‘sh PClarni lock qilish',
                'watch_action_lock_idle_body' => 'Operator aralashuvisiz turgan bo‘sh online PClarni tezda bloklash planini tayyorlaydi.',
                'watch_action_offline_title' => 'Offline PClarni tekshirish',
                'watch_action_offline_body' => 'Qaysi kompyuterlar uzilib qolganini ro‘yxat qilib beradi.',
                'watch_action_shutdown_idle_title' => 'Bo‘sh PClarni o‘chirish',
                'watch_action_shutdown_idle_body' => 'Shutdown xavfli amal, lekin Nexora oldin plan ko‘rsatib tasdiq so‘raydi.',
                'watch_action_revenue_title' => 'Bugungi tushumni aytish',
                'watch_action_revenue_body' => 'Bugungi gross va net tushumni tezkor summary qilib beradi.',
                'autopilot_forbidden' => 'Nexora Autopilot sozlamalarini faqat owner yoki admin yangilashi mumkin.',
                'autopilot_updated' => 'Nexora Autopilot sozlamalari yangilandi.',
                'unsupported' => 'Hozircha men zal holatini aytish, bo‘sh yoki offline PClarni ko‘rsatish, bugungi tushumni aytish, bo‘sh PClarni lock/shutdown qilish, aniq PC larni reboot qilish va zona bo‘yicha xabar yuborishga yordam bera olaman.',
                'plan_not_found' => 'Nexora plan topilmadi yoki muddati tugagan.',
                'plan_not_executable' => 'Bu Nexora plan bajariladigan turga kirmaydi.',
                'confirmation_required' => 'Bu amal uchun tasdiq kerak.',
            ],
            'ru' => [
                'action_hall_snapshot' => 'Сводка зала',
                'action_idle_pc_list' => 'Свободные ПК',
                'action_shutdown_idle_pcs' => 'Выключение пустых ПК',
                'action_lock_idle_pcs' => 'Блокировка пустых ПК',
                'action_reboot_named_pcs' => 'Перезагрузка ПК',
                'action_message_zone_pcs' => 'Сообщение по зоне',
                'action_today_revenue' => 'Выручка сегодня',
                'action_offline_pc_list' => 'Офлайн ПК',
                'action_unsupported' => 'Помощь',
                'hall_snapshot' => 'Сейчас в зале :online ПК онлайн, :active_sessions активных сессий и :idle свободных онлайн ПК. Ближайшие свободные: :codes.',
                'idle_pc_list' => 'Сейчас свободные и включенные ПК: :codes.',
                'idle_pc_list_empty' => 'Сейчас нет свободных и включенных ПК.',
                'shutdown_idle_plan' => 'Я нашла :count свободных включенных ПК: :codes. Подтвердите, и я отправлю shutdown.',
                'shutdown_idle_empty' => 'Сейчас нет подходящих пустых ПК для shutdown.',
                'shutdown_idle_executed' => 'Команда shutdown отправлена на :count ПК.',
                'lock_idle_plan' => 'Я нашла :count свободных онлайн ПК: :codes. Подтвердите, и я отправлю lock.',
                'lock_idle_empty' => 'Нет подходящих свободных онлайн ПК для lock.',
                'lock_idle_executed' => 'Команда lock отправлена на :count ПК.',
                'reboot_need_pc_codes' => 'Напишите, какие ПК перезагрузить. Например: перезагрузи PC-1 и PC-2.',
                'reboot_plan' => 'Готова перезагрузить следующие ПК: :codes. Подтвердите действие.',
                'reboot_empty' => 'Сейчас нет подходящих ПК для reboot.',
                'reboot_executed' => 'Команда reboot отправлена на :count ПК.',
                'message_zone_need_zone' => 'Укажите зону. Например: отправь в VIP зону "Турнир начнется через 10 минут".',
                'message_zone_need_text' => 'Текст сообщения нужно указать в кавычках. Например: отправь в VIP зону "Турнир начнется через 10 минут".',
                'message_zone_plan' => 'Я отправлю в зону :zone сообщение на :count ПК: ":text". Подтвердите действие.',
                'message_zone_empty' => 'В зоне :zone нет онлайн ПК.',
                'message_zone_executed' => 'Сообщение отправлено на :count ПК.',
                'today_revenue' => 'Сегодня gross выручка :gross UZS, net выручка :net UZS. Сессий: :sessions.',
                'offline_pc_list' => 'Сейчас офлайн или недоступны ПК: :codes.',
                'offline_pc_list_empty' => 'Сейчас офлайн ПК нет.',
                'watch_summary_title' => 'Nexora Watch',
                'watch_summary_text' => 'Сейчас :online ПК онлайн, :idle свободных онлайн ПК и :offline офлайн ПК. Текущая загрузка около :load%.',
                'alert_idle_title' => 'Слишком много свободных включенных ПК',
                'alert_idle_body' => ':count ПК сейчас простаивают. Ближайшие: :codes.',
                'alert_offline_title' => 'Есть офлайн ПК',
                'alert_offline_body' => ':count ПК офлайн или недоступны. Основные: :codes.',
                'alert_load_title' => 'Низкая загрузка зала',
                'alert_load_body' => 'Сейчас только :active сессий при :online ПК онлайн. Nexora советует проверить ситуацию.',
                'prompt_lock_idle_pcs' => 'Заблокируй свободные и включенные компьютеры.',
                'prompt_show_offline' => 'Покажи офлайн компьютеры.',
                'prompt_hall_snapshot' => 'Какая сейчас ситуация в зале?',
                'prompt_shutdown_idle_pcs' => 'Выключи все включенные компьютеры без людей.',
                'prompt_today_revenue' => 'Какая выручка сегодня?',
                'watch_action_lock_idle_title' => 'Заблокировать пустые ПК',
                'watch_action_lock_idle_body' => 'Подготовит быстрый план блокировки свободных онлайн компьютеров.',
                'watch_action_offline_title' => 'Проверить офлайн ПК',
                'watch_action_offline_body' => 'Покажет список компьютеров, которые сейчас не на связи.',
                'watch_action_shutdown_idle_title' => 'Выключить пустые ПК',
                'watch_action_shutdown_idle_body' => 'Рискованное действие, поэтому Nexora сначала покажет план и запросит подтверждение.',
                'watch_action_revenue_title' => 'Показать выручку за сегодня',
                'watch_action_revenue_body' => 'Вернет быстрый gross/net summary за текущий день.',
                'autopilot_forbidden' => 'Настройки Nexora Autopilot может менять только owner или admin.',
                'autopilot_updated' => 'Настройки Nexora Autopilot обновлены.',
                'unsupported' => 'Пока я умею показывать состояние зала, список свободных или офлайн ПК, выручку за сегодня, готовить lock/shutdown для пустых ПК, reboot по указанным ПК и отправку сообщений по зоне.',
                'plan_not_found' => 'План Nexora не найден или уже истек.',
                'plan_not_executable' => 'Этот план Nexora нельзя выполнить.',
                'confirmation_required' => 'Для этого действия требуется подтверждение.',
            ],
            'en' => [
                'action_hall_snapshot' => 'Hall snapshot',
                'action_idle_pc_list' => 'Idle PCs',
                'action_shutdown_idle_pcs' => 'Shutdown idle PCs',
                'action_lock_idle_pcs' => 'Lock idle PCs',
                'action_reboot_named_pcs' => 'Reboot PCs',
                'action_message_zone_pcs' => 'Zone message',
                'action_today_revenue' => 'Today revenue',
                'action_offline_pc_list' => 'Offline PCs',
                'action_unsupported' => 'Help',
                'hall_snapshot' => 'The hall currently has :online online PCs, :active_sessions active sessions, and :idle idle online PCs. The nearest free ones are: :codes.',
                'idle_pc_list' => 'Idle powered-on PCs right now: :codes.',
                'idle_pc_list_empty' => 'There are no idle powered-on PCs right now.',
                'shutdown_idle_plan' => 'I found :count idle powered-on PCs: :codes. Confirm and I will send shutdown.',
                'shutdown_idle_empty' => 'There are no eligible idle PCs to shut down right now.',
                'shutdown_idle_executed' => 'Shutdown was sent to :count PCs.',
                'lock_idle_plan' => 'I found :count idle online PCs: :codes. Confirm and I will send lock.',
                'lock_idle_empty' => 'There are no eligible idle online PCs to lock right now.',
                'lock_idle_executed' => 'Lock was sent to :count PCs.',
                'reboot_need_pc_codes' => 'Tell me which PCs to reboot. Example: reboot PC-1 and PC-2.',
                'reboot_plan' => 'I am ready to reboot these PCs: :codes. Confirm to continue.',
                'reboot_empty' => 'There are no eligible PCs to reboot right now.',
                'reboot_executed' => 'Reboot was sent to :count PCs.',
                'message_zone_need_zone' => 'Tell me which zone should receive the message. Example: send to VIP zone "Tournament starts in 10 minutes".',
                'message_zone_need_text' => 'Put the message text in quotes. Example: send to VIP zone "Tournament starts in 10 minutes".',
                'message_zone_plan' => 'I will send this message to :count PCs in zone :zone: ":text". Confirm to continue.',
                'message_zone_empty' => 'There are no online PCs in zone :zone.',
                'message_zone_executed' => 'The message was sent to :count PCs.',
                'today_revenue' => 'Today gross revenue is :gross UZS and net revenue is :net UZS. Sessions: :sessions.',
                'offline_pc_list' => 'Offline or unreachable PCs right now: :codes.',
                'offline_pc_list_empty' => 'There are no offline PCs right now.',
                'watch_summary_title' => 'Nexora Watch',
                'watch_summary_text' => 'There are :online online PCs, :idle idle online PCs, and :offline offline PCs right now. Current load is about :load%.',
                'alert_idle_title' => 'Too many idle powered-on PCs',
                'alert_idle_body' => ':count PCs are sitting idle right now. The nearest ones are: :codes.',
                'alert_offline_title' => 'Offline PCs detected',
                'alert_offline_body' => ':count PCs are offline or unreachable. Key ones: :codes.',
                'alert_load_title' => 'Hall load is low',
                'alert_load_body' => 'Only :active sessions are active while :online PCs are online. Nexora suggests checking the room status.',
                'prompt_lock_idle_pcs' => 'Lock idle powered-on computers.',
                'prompt_show_offline' => 'Show offline computers.',
                'prompt_hall_snapshot' => 'What is the current hall status?',
                'prompt_shutdown_idle_pcs' => 'Shut down all powered-on PCs without active users.',
                'prompt_today_revenue' => 'What is today revenue?',
                'watch_action_lock_idle_title' => 'Lock idle PCs',
                'watch_action_lock_idle_body' => 'Prepare a quick plan to lock idle online PCs before the room drifts.',
                'watch_action_offline_title' => 'Inspect offline PCs',
                'watch_action_offline_body' => 'Show the machines that are currently disconnected or stale.',
                'watch_action_shutdown_idle_title' => 'Shut down idle PCs',
                'watch_action_shutdown_idle_body' => 'This is risky, so Nexora will prepare a plan and ask for confirmation first.',
                'watch_action_revenue_title' => 'Check today revenue',
                'watch_action_revenue_body' => 'Return a quick gross and net summary for the current day.',
                'autopilot_forbidden' => 'Only an owner or admin can update Nexora Autopilot settings.',
                'autopilot_updated' => 'Nexora Autopilot settings were updated.',
                'unsupported' => 'For now I can summarize the hall, show idle or offline PCs, tell today revenue, prepare lock/shutdown for idle PCs, reboot named PCs, and send a message to a zone.',
                'plan_not_found' => 'The Nexora plan was not found or has expired.',
                'plan_not_executable' => 'This Nexora plan cannot be executed.',
                'confirmation_required' => 'Confirmation is required for this action.',
            ],
        ];

        $catalog = $dictionary[$locale] ?? $dictionary['uz'];
        $text = $catalog[$key] ?? ($dictionary['uz'][$key] ?? $key);

        foreach ($replace as $name => $value) {
            $text = str_replace(':' . $name, (string) $value, $text);
        }

        return $text;
    }
}
