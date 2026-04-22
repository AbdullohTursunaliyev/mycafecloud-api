<?php

namespace App\Services;

use App\Enums\PaymentMethod;
use App\Models\Client;
use App\Models\ClientTransaction;
use App\Models\ClientTransfer;
use App\Models\Operator;
use App\Models\Shift;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ClientTransferService
{
    public function paginate(int $tenantId, ?int $shiftId = null, int $perPage = 20): LengthAwarePaginator
    {
        $query = ClientTransfer::query()
            ->where('tenant_id', $tenantId)
            ->with([
                'fromClient:id,login,phone',
                'toClient:id,login,phone',
                'operator:id,login,role',
                'shift:id,opened_at,closed_at',
            ])
            ->orderByDesc('id');

        if ($shiftId !== null) {
            $query->where('shift_id', $shiftId);
        }

        return $query->paginate($perPage);
    }

    public function transfer(
        int $tenantId,
        Operator $operator,
        int $fromClientId,
        int $toClientId,
        int $amount,
    ): array {
        if ($fromClientId === $toClientId) {
            throw ValidationException::withMessages([
                'to_client_id' => 'Cannot transfer to the same client',
            ]);
        }

        return DB::transaction(function () use ($tenantId, $operator, $fromClientId, $toClientId, $amount) {
            $shift = Shift::query()
                ->where('tenant_id', $tenantId)
                ->whereNull('closed_at')
                ->lockForUpdate()
                ->latest('id')
                ->first();

            if (!$shift) {
                throw ValidationException::withMessages([
                    'shift' => 'Shift is not open',
                ]);
            }

            $from = Client::query()
                ->where('tenant_id', $tenantId)
                ->whereKey($fromClientId)
                ->lockForUpdate()
                ->firstOrFail();

            $to = Client::query()
                ->where('tenant_id', $tenantId)
                ->whereKey($toClientId)
                ->lockForUpdate()
                ->firstOrFail();

            if ((int) $from->balance < $amount) {
                throw ValidationException::withMessages([
                    'amount' => 'Insufficient balance',
                ]);
            }

            $from->balance = (int) $from->balance - $amount;
            $to->balance = (int) $to->balance + $amount;
            $from->save();
            $to->save();

            ClientTransaction::query()->create([
                'tenant_id' => $tenantId,
                'client_id' => $from->id,
                'operator_id' => $operator->id,
                'shift_id' => $shift->id,
                'type' => 'transfer_out',
                'amount' => -1 * $amount,
                'bonus_amount' => 0,
                'payment_method' => PaymentMethod::Balance->value,
                'comment' => 'Transfer to ' . ($to->login ?? $to->phone ?? ('#' . $to->id)),
            ]);

            ClientTransaction::query()->create([
                'tenant_id' => $tenantId,
                'client_id' => $to->id,
                'operator_id' => $operator->id,
                'shift_id' => $shift->id,
                'type' => 'transfer_in',
                'amount' => $amount,
                'bonus_amount' => 0,
                'payment_method' => PaymentMethod::Balance->value,
                'comment' => 'Transfer from ' . ($from->login ?? $from->phone ?? ('#' . $from->id)),
            ]);

            $transfer = ClientTransfer::query()->create([
                'tenant_id' => $tenantId,
                'from_client_id' => $from->id,
                'to_client_id' => $to->id,
                'operator_id' => $operator->id,
                'shift_id' => $shift->id,
                'amount' => $amount,
            ]);

            return [
                'transfer' => $transfer,
                'sender' => ['id' => $from->id, 'balance' => (int) $from->balance],
                'receiver' => ['id' => $to->id, 'balance' => (int) $to->balance],
            ];
        });
    }
}
