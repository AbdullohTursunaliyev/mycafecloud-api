<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\ClientTransaction;
use App\Models\ClientTransfer;
use App\Models\Shift;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class TransferController extends Controller
{
    private function currentShift(int $tenantId): ?Shift
    {
        return Shift::query()
            ->where('tenant_id', $tenantId)
            ->whereNull('closed_at')
            ->latest('id')
            ->first();
    }

    public function index(Request $request)
    {
        $tenantId = $request->user()->tenant_id;

        $q = ClientTransfer::query()
            ->where('tenant_id', $tenantId)
            ->with([
                'fromClient:id,login,phone',
                'toClient:id,login,phone',
                'operator:id,login,role',
                'shift:id,opened_at,closed_at',
            ])
            ->orderByDesc('id');

        if ($request->filled('shift_id')) {
            $q->where('shift_id', (int)$request->shift_id);
        }

        return response()->json(['data' => $q->paginate(20)]);
    }

    public function store(Request $request, int $id)
    {
        $operator = $request->user();
        $tenantId = $operator->tenant_id;

        $data = $request->validate([
            'to_client_id' => 'required|integer',
            'amount' => 'required|integer|min:1',
        ]);

        if ((int)$data['to_client_id'] === (int)$id) {
            throw ValidationException::withMessages(['to_client_id' => 'Cannot transfer to the same client']);
        }

        $shift = $this->currentShift($tenantId);
        if (!$shift) {
            throw ValidationException::withMessages(['shift' => 'Shift is not open']);
        }

        return DB::transaction(function () use ($data, $tenantId, $operator, $shift, $id) {
            $from = Client::query()
                ->where('tenant_id', $tenantId)
                ->where('id', (int)$id)
                ->lockForUpdate()
                ->firstOrFail();

            $to = Client::query()
                ->where('tenant_id', $tenantId)
                ->where('id', (int)$data['to_client_id'])
                ->lockForUpdate()
                ->firstOrFail();

            $amount = (int)$data['amount'];

            if ((int)$from->balance < $amount) {
                throw ValidationException::withMessages(['amount' => 'Insufficient balance']);
            }

            $from->balance = (int)$from->balance - $amount;
            $to->balance = (int)$to->balance + $amount;
            $from->save();
            $to->save();

            ClientTransaction::create([
                'tenant_id' => $tenantId,
                'client_id' => $from->id,
                'operator_id' => $operator->id,
                'shift_id' => $shift->id,
                'type' => 'transfer_out',
                'amount' => -1 * $amount,
                'bonus_amount' => 0,
                'payment_method' => 'balance',
                'comment' => 'Transfer to ' . ($to->login ?? $to->phone ?? ('#' . $to->id)),
            ]);

            ClientTransaction::create([
                'tenant_id' => $tenantId,
                'client_id' => $to->id,
                'operator_id' => $operator->id,
                'shift_id' => $shift->id,
                'type' => 'transfer_in',
                'amount' => $amount,
                'bonus_amount' => 0,
                'payment_method' => 'balance',
                'comment' => 'Transfer from ' . ($from->login ?? $from->phone ?? ('#' . $from->id)),
            ]);

            $transfer = ClientTransfer::create([
                'tenant_id' => $tenantId,
                'from_client_id' => $from->id,
                'to_client_id' => $to->id,
                'operator_id' => $operator->id,
                'shift_id' => $shift->id,
                'amount' => $amount,
            ]);

            return response()->json([
                'data' => [
                    'transfer' => $transfer,
                    'sender' => ['id' => $from->id, 'balance' => (int)$from->balance],
                    'receiver' => ['id' => $to->id, 'balance' => (int)$to->balance],
                ],
            ], 201);
        });
    }
}
