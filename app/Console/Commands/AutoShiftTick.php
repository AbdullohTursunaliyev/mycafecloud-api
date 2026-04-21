<?php

namespace App\Console\Commands;

use App\Models\ClientTransaction;
use App\Models\Operator;
use App\Models\ReturnRecord;
use App\Models\Setting;
use App\Models\Shift;
use App\Service\TelegramShiftNotifier;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class AutoShiftTick extends Command
{
    protected $signature = 'shifts:auto-tick';
    protected $description = 'Auto open/close shifts by per-tenant time slots';

    public function handle(): int
    {
        $now = now()->seconds(0);

        $rows = Setting::query()
            ->whereIn('key', [
                'auto_shift_enabled',
                'auto_shift_slots',
                'auto_shift_opening_cash',
                'auto_shift_operator_id',
            ])
            ->get(['tenant_id', 'key', 'value'])
            ->groupBy('tenant_id');

        $processed = 0;
        $opened = 0;
        $closed = 0;

        foreach ($rows as $tenantId => $items) {
            $map = [];
            foreach ($items as $row) {
                $map[$row->key] = $row->value;
            }

            if (!$this->asBool($map['auto_shift_enabled'] ?? false)) {
                continue;
            }

            $slots = $this->normalizeSlots($map['auto_shift_slots'] ?? []);
            if (!$slots) {
                continue;
            }

            $processed++;

            $operatorId = $this->resolveOperatorId((int)$tenantId, $map['auto_shift_operator_id'] ?? null);
            if (!$operatorId) {
                $this->warn("auto-shift tenant={$tenantId}: operator not found");
                continue;
            }

            $active = Shift::query()
                ->where('tenant_id', (int)$tenantId)
                ->whereNull('closed_at')
                ->latest('id')
                ->first();

            $shouldClose = false;
            $startSlot = null;

            foreach ($slots as $slot) {
                if ($slot['end'] === $now->format('H:i')) {
                    $shouldClose = true;
                }
                if ($slot['start'] === $now->format('H:i')) {
                    $startSlot = $slot;
                }
            }

            if ($shouldClose && $active) {
                if ($this->closeShift((int)$tenantId, $active, $operatorId, $now)) {
                    $closed++;
                    $active = null;
                }
            }

            if ($startSlot && !$active) {
                $openingCash = max(0, (int)($map['auto_shift_opening_cash'] ?? 0));
                if ($this->openShift((int)$tenantId, $operatorId, $now, $openingCash, $startSlot)) {
                    $opened++;
                }
            }
        }

        $this->info("auto-shift processed={$processed}, opened={$opened}, closed={$closed}");
        return self::SUCCESS;
    }

    private function openShift(int $tenantId, int $operatorId, $now, int $openingCash, array $slot): bool
    {
        $created = null;

        DB::transaction(function () use ($tenantId, $operatorId, $now, $openingCash, $slot, &$created) {
            $exists = Shift::query()
                ->where('tenant_id', $tenantId)
                ->whereNull('closed_at')
                ->lockForUpdate()
                ->exists();

            if ($exists) {
                return;
            }

            $created = Shift::create([
                'tenant_id' => $tenantId,
                'opened_by_operator_id' => $operatorId,
                'opened_at' => $now->copy(),
                'opening_cash' => $openingCash,
                'topups_cash_total' => 0,
                'topups_card_total' => 0,
                'packages_cash_total' => 0,
                'packages_card_total' => 0,
                'returns_total' => 0,
                'diff_overage' => 0,
                'diff_shortage' => 0,
                'adjustments_total' => 0,
                'status' => 'open',
                'meta' => [
                    'auto_shift' => true,
                    'slot' => $slot,
                ],
            ]);
        });

        if (!$created) {
            return false;
        }

        $created->load(['openedBy:id,login']);

        TelegramShiftNotifier::shiftOpened($tenantId, [
            'shift_id' => $created->id,
            'opened_by' => $created->openedBy?->login ?? ('#' . $operatorId),
            'opened_at' => (string)$created->opened_at,
            'opening_cash' => (int)$created->opening_cash,
        ]);

        return true;
    }

    private function closeShift(int $tenantId, Shift $shift, int $operatorId, $now): bool
    {
        $payload = null;

        DB::transaction(function () use ($tenantId, $shift, $operatorId, $now, &$payload) {
            $locked = Shift::query()
                ->where('tenant_id', $tenantId)
                ->whereKey($shift->id)
                ->lockForUpdate()
                ->first();

            if (!$locked || $locked->closed_at !== null) {
                return;
            }

            $baseTopups = ClientTransaction::query()
                ->where('tenant_id', $tenantId)
                ->where('shift_id', $locked->id)
                ->where('type', 'topup');

            $cashTotal = (int)(clone $baseTopups)->where('payment_method', 'cash')->sum('amount');
            $cardTotal = (int)(clone $baseTopups)->where('payment_method', 'card')->sum('amount');
            $bonusTotal = (int)(clone $baseTopups)->sum('bonus_amount');
            $opsCount = (int)(clone $baseTopups)->count();

            $returnsTotal = (int)ReturnRecord::query()
                ->where('tenant_id', $tenantId)
                ->where('shift_id', $locked->id)
                ->where(function ($q) {
                    $q->whereNull('payment_method')
                        ->orWhere('payment_method', '!=', 'balance');
                })
                ->sum('amount');

            $returnsCashTotal = (int)ReturnRecord::query()
                ->where('tenant_id', $tenantId)
                ->where('shift_id', $locked->id)
                ->where('payment_method', 'cash')
                ->sum('amount');

            $summaryBase = ClientTransaction::query()
                ->where('tenant_id', $tenantId)
                ->where('shift_id', $locked->id)
                ->whereIn('type', ['topup', 'package', 'subscription']);

            $summaryCash = (int)(clone $summaryBase)->where('payment_method', 'cash')->sum('amount');

            $expensesTotal = (int)\App\Models\ShiftExpense::query()
                ->where('tenant_id', $tenantId)
                ->where('shift_id', $locked->id)
                ->sum('amount');

            $expectedCash = (int)$locked->opening_cash
                + $summaryCash
                - $expensesTotal
                - $returnsCashTotal;

            $closingCash = $expectedCash;
            $diff = $closingCash - $expectedCash;
            $diffOver = $diff > 0 ? $diff : 0;
            $diffShort = $diff < 0 ? abs($diff) : 0;

            $meta = $locked->meta ?? [];
            $meta['topups_bonus_total'] = $bonusTotal;
            $meta['topups_ops_count'] = $opsCount;
            $meta['auto_shift'] = true;
            $meta['closed_by_auto'] = true;

            $locked->update([
                'closed_at' => $now->copy(),
                'closing_cash' => $closingCash,
                'closed_by_operator_id' => $operatorId,
                'topups_cash_total' => $cashTotal,
                'topups_card_total' => $cardTotal,
                'returns_total' => $returnsTotal,
                'diff_overage' => $diffOver,
                'diff_shortage' => $diffShort,
                'status' => 'closed',
                'meta' => $meta,
            ]);

            $locked->refresh()->load(['openedBy:id,login', 'closedBy:id,login']);

            $payload = [
                'shift_id' => $locked->id,
                'opened_by' => $locked->openedBy?->login ?? '-',
                'closed_by' => $locked->closedBy?->login ?? ('#' . $operatorId),
                'opened_at' => (string)$locked->opened_at,
                'closed_at' => (string)$locked->closed_at,
                'opening_cash' => (int)$locked->opening_cash,
                'closing_cash' => (int)$locked->closing_cash,
                'topups_cash_total' => $cashTotal,
                'topups_card_total' => $cardTotal,
                'topups_bonus_total' => $bonusTotal,
                'topups_ops_count' => $opsCount,
                'returns_total' => $returnsTotal,
                'returns_cash_total' => $returnsCashTotal,
                'expenses_total' => $expensesTotal,
                'expected_cash' => $expectedCash,
                'diff_overage' => $diffOver,
                'diff_shortage' => $diffShort,
            ];
        });

        if (!$payload) {
            return false;
        }

        TelegramShiftNotifier::shiftClosed($tenantId, $payload);
        return true;
    }

    private function resolveOperatorId(int $tenantId, mixed $candidate): ?int
    {
        if (is_numeric($candidate)) {
            $id = (int)$candidate;
            $exists = Operator::query()
                ->where('tenant_id', $tenantId)
                ->whereKey($id)
                ->where('is_active', true)
                ->exists();
            if ($exists) {
                return $id;
            }
        }

        $ownerAdmin = Operator::query()
            ->where('tenant_id', $tenantId)
            ->where('is_active', true)
            ->whereIn('role', ['owner', 'admin'])
            ->orderByRaw("CASE WHEN role = 'owner' THEN 0 ELSE 1 END")
            ->orderBy('id')
            ->value('id');

        if ($ownerAdmin) {
            return (int)$ownerAdmin;
        }

        $any = Operator::query()
            ->where('tenant_id', $tenantId)
            ->where('is_active', true)
            ->orderBy('id')
            ->value('id');

        return $any ? (int)$any : null;
    }

    private function normalizeSlots(mixed $raw): array
    {
        if (!is_array($raw)) {
            return [];
        }

        $out = [];
        foreach ($raw as $idx => $slot) {
            if (!is_array($slot)) {
                continue;
            }

            $start = (string)($slot['start'] ?? $slot['from'] ?? '');
            $end = (string)($slot['end'] ?? $slot['to'] ?? '');
            $label = trim((string)($slot['label'] ?? ''));

            if (!$this->isValidTime($start) || !$this->isValidTime($end) || $start === $end) {
                continue;
            }

            $out[] = [
                'start' => $start,
                'end' => $end,
                'label' => $label !== '' ? $label : ('Smena ' . ($idx + 1)),
            ];
        }

        return $out;
    }

    private function isValidTime(string $value): bool
    {
        return (bool)preg_match('/^(2[0-3]|[01]\d):[0-5]\d$/', $value);
    }

    private function asBool(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        $v = strtolower(trim((string)$value));
        return !in_array($v, ['', '0', 'false', 'off', 'no', 'null'], true);
    }
}
