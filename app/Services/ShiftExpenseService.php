<?php

namespace App\Services;

use App\Models\ShiftExpense;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ShiftExpenseService
{
    public function __construct(
        private readonly ShiftService $shifts,
    ) {
    }

    public function current(int $tenantId, int $limit): array
    {
        $shift = $this->shifts->currentShift($tenantId);
        if (!$shift) {
            return ['shift' => null, 'items' => [], 'total' => 0];
        }

        $items = ShiftExpense::query()
            ->where('tenant_id', $tenantId)
            ->where('shift_id', $shift->id)
            ->with(['operator:id,login,name,role'])
            ->orderByDesc('spent_at')
            ->orderByDesc('id')
            ->limit(min($limit, 50))
            ->get();

        $total = (int) ShiftExpense::query()
            ->where('tenant_id', $tenantId)
            ->where('shift_id', $shift->id)
            ->sum(DB::raw('ABS(amount)'));

        return [
            'shift' => [
                'id' => (int) $shift->id,
                'opened_at' => optional($shift->opened_at)->toIso8601String(),
                'opening_cash' => (int) $shift->opening_cash,
            ],
            'items' => $items,
            'total' => $total,
        ];
    }

    public function store(int $tenantId, int $operatorId, array $payload): ShiftExpense
    {
        $shift = $this->shifts->currentShift($tenantId);
        if (!$shift) {
            throw ValidationException::withMessages([
                'shift' => 'Смена не открыта',
            ]);
        }

        $expense = DB::transaction(function () use ($tenantId, $operatorId, $shift, $payload) {
            return ShiftExpense::query()->create([
                'tenant_id' => $tenantId,
                'shift_id' => $shift->id,
                'operator_id' => $operatorId,
                'amount' => (int) $payload['amount'],
                'title' => $payload['title'],
                'category' => $payload['category'] ?? null,
                'note' => $payload['note'] ?? null,
                'spent_at' => $payload['spent_at'] ?? now(),
            ]);
        });

        return $expense->load(['operator:id,login,name,role']);
    }

    public function destroy(int $tenantId, int $expenseId): void
    {
        $expense = ShiftExpense::query()
            ->where('tenant_id', $tenantId)
            ->findOrFail($expenseId);

        $shift = $expense->shift()->first();
        if ($shift && $shift->closed_at) {
            throw ValidationException::withMessages([
                'expense' => 'Нельзя удалить расход в закрытой смене',
            ]);
        }

        DB::transaction(function () use ($expense): void {
            $expense->delete();
        });
    }
}
