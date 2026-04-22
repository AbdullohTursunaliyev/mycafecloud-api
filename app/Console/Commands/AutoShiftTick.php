<?php

namespace App\Console\Commands;

use App\Models\Operator;
use App\Models\Setting;
use App\Models\Shift;
use App\Services\ShiftService;
use Illuminate\Console\Command;

class AutoShiftTick extends Command
{
    protected $signature = 'shifts:auto-tick';
    protected $description = 'Auto open/close shifts by per-tenant time slots';

    public function __construct(
        private readonly ShiftService $shifts,
    ) {
        parent::__construct();
    }

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
        try {
            $this->shifts->openShift(
                $tenantId,
                $operatorId,
                $openingCash,
                $now->copy(),
                [
                    'auto_shift' => true,
                    'slot' => $slot,
                ],
            );
        } catch (\Illuminate\Validation\ValidationException) {
            return false;
        }

        return true;
    }

    private function closeShift(int $tenantId, Shift $shift, int $operatorId, $now): bool
    {
        try {
            $this->shifts->closeShift(
                $tenantId,
                $shift,
                $operatorId,
                null,
                $now->copy(),
                [
                    'auto_shift' => true,
                    'closed_by_auto' => true,
                ],
            );
        } catch (\Illuminate\Validation\ValidationException) {
            return false;
        }

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
