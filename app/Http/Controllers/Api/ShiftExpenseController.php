<?php

// app/Http/Controllers/Api/ShiftExpenseController.php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Shift;
use App\Models\ShiftExpense;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ShiftExpenseController extends Controller
{
    // GET /api/shifts/current/expenses?limit=20
    public function current(Request $request)
    {
        $tenantId = $request->user()->tenant_id;
        $limit = (int)($request->get('limit', 20));

        $shift = Shift::query()
            ->where('tenant_id', $tenantId)
            ->whereNull('closed_at')
            ->latest('id')
            ->first();

        if (!$shift) {
            return response()->json(['data' => ['shift' => null, 'items' => [], 'total' => 0]]);
        }

        $q = ShiftExpense::query()
            ->where('tenant_id', $tenantId)
            ->where('shift_id', $shift->id)
            ->orderByDesc('spent_at')
            ->orderByDesc('id');

        $items = $q->limit(min($limit, 50))
            ->with(['operator:id,login,name,role'])
            ->get();

        $total = (int) ShiftExpense::query()
            ->where('tenant_id', $tenantId)
            ->where('shift_id', $shift->id)
            ->sum(DB::raw('ABS(amount)'));

        return response()->json([
            'data' => [
                'shift' => $shift->only(['id','opened_at','opening_cash']),
                'items' => $items,
                'total' => $total,
            ],
        ]);
    }

    // POST /api/shifts/current/expenses
    public function store(Request $request)
    {
        $tenantId = $request->user()->tenant_id;
        $operatorId = $request->user()->id;

        $data = $request->validate([
            'amount' => ['required','integer','min:1'],
            'title' => ['required','string','max:120'],
            'category' => ['nullable','string','max:64'],
            'note' => ['nullable','string','max:255'],
            'spent_at' => ['nullable','date'], // ixtiyoriy, bo‘sh bo‘lsa now()
        ]);

        $shift = Shift::query()
            ->where('tenant_id', $tenantId)
            ->whereNull('closed_at')
            ->latest('id')
            ->first();

        if (!$shift) {
            throw ValidationException::withMessages([
                'shift' => 'Смена не открыта',
            ]);
        }

        $expense = DB::transaction(function () use ($tenantId, $operatorId, $shift, $data) {
            $e = ShiftExpense::create([
                'tenant_id' => $tenantId,
                'shift_id' => $shift->id,
                'operator_id' => $operatorId,
                'amount' => (int)$data['amount'],
                'title' => $data['title'],
                'category' => $data['category'] ?? null,
                'note' => $data['note'] ?? null,
                'spent_at' => $data['spent_at'] ?? now(),
            ]);

            return $e;
        });

        $expense->load(['operator:id,login,name,role']);

        return response()->json(['data' => $expense], 201);
    }

    // DELETE /api/shifts/expenses/{id}
    public function destroy(Request $request, int $id)
    {
        $tenantId = $request->user()->tenant_id;

        $expense = ShiftExpense::query()
            ->where('tenant_id', $tenantId)
            ->findOrFail($id);

        // optional: faqat ochiq shift ichida o‘chirish
        $shift = Shift::query()->where('tenant_id',$tenantId)->find($expense->shift_id);
        if ($shift && $shift->closed_at) {
            throw ValidationException::withMessages([
                'expense' => 'Нельзя удалить расход в закрытой смене',
            ]);
        }

        DB::transaction(function () use ($expense) {
            $expense->delete();
        });

        return response()->json(['ok' => true]);
    }
}
