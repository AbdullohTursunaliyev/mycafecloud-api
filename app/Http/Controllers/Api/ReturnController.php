<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\ClientTransaction;
use App\Models\PackageSale;
use App\Models\ReturnRecord;
use App\Models\Shift;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ReturnController extends Controller
{
    private function isTopupType(string $type): bool
    {
        $t = strtolower($type);
        return str_contains($t, 'topup') || str_contains($t, 'deposit') || str_contains($t, 'pay');
    }

    private function currentShift(int $tenantId): ?Shift
    {
        return Shift::query()
            ->where('tenant_id', $tenantId)
            ->whereNull('closed_at')
            ->latest('id')
            ->first();
    }

    private function withinFiveMinutes($createdAt): bool
    {
        return now()->diffInSeconds($createdAt) <= 300;
    }

    public function index(Request $request)
    {
        $tenantId = $request->user()->tenant_id;

        $q = ReturnRecord::query()
            ->where('tenant_id', $tenantId)
            ->with([
                'client:id,login,phone',
                'operator:id,login,role',
                'shift:id,opened_at,closed_at',
            ])
            ->orderByDesc('id');

        if ($request->filled('shift_id')) {
            $q->where('shift_id', (int)$request->shift_id);
        }

        return response()->json(['data' => $q->paginate(20)]);
    }

    public function options(Request $request, int $id)
    {
        $tenantId = $request->user()->tenant_id;
        $client = Client::where('tenant_id', $tenantId)
            ->where(function ($q) use ($id) {
                $q->where('id', $id)
                    ->orWhere('login', (string)$id)
                    ->orWhere('account_id', (string)$id);
            })
            ->firstOrFail();

        $shift = $this->currentShift($tenantId);

        $topups = ClientTransaction::query()
            ->where('tenant_id', $tenantId)
            ->where('client_id', $client->id)
            ->orderByDesc('id')
            ->limit(30)
            ->get()
            ->filter(function ($tx) {
                return $this->isTopupType((string)$tx->type);
            })
            ->values()
            ->map(function ($tx) use ($tenantId, $shift, $client) {
                $reason = null;

                if (!$shift) {
                    $reason = 'Shift closed';
                } elseif ((int)$tx->shift_id !== (int)$shift->id) {
                    $reason = 'Different shift';
                } elseif (!$this->withinFiveMinutes($tx->created_at)) {
                    $reason = 'More than 5 minutes';
                } elseif (ReturnRecord::where('tenant_id', $tenantId)
                    ->where('source_type', 'client_transaction')
                    ->where('source_id', $tx->id)
                    ->exists()
                ) {
                    $reason = 'Already returned';
                } elseif (str_contains(strtolower((string)$tx->payment_method), 'card')) {
                    $reason = 'Card topups cannot be returned';
                } elseif ((int)$client->balance < (int)$tx->amount) {
                    $reason = 'Insufficient balance';
                } elseif ((int)$client->bonus < (int)$tx->bonus_amount) {
                    $reason = 'Insufficient bonus';
                }

                return [
                    'id' => $tx->id,
                    'amount' => (int)$tx->amount,
                    'bonus_amount' => (int)$tx->bonus_amount,
                    'payment_method' => $tx->payment_method,
                    'created_at' => $tx->created_at,
                    'eligible' => $reason === null,
                    'reason' => $reason,
                ];
            })
            ->filter(fn($x) => $x['eligible'] === true)
            ->values();

        $packages = PackageSale::query()
            ->where('tenant_id', $tenantId)
            ->where('client_id', $client->id)
            ->orderByDesc('id')
            ->limit(30)
            ->get()
            ->map(function ($sale) use ($tenantId, $shift) {
                $reason = null;

                if (!$shift) {
                    $reason = 'Shift closed';
                } else {
                    if ($sale->payment_method === 'cash' || $sale->payment_method === 'card') {
                        if ((int)$sale->shift_id !== (int)$shift->id) {
                            $reason = 'Different shift';
                        }
                    } else {
                        if ($sale->created_at < $shift->opened_at) {
                            $reason = 'Different shift';
                        }
                    }
                }

                if ($reason === null && !$this->withinFiveMinutes($sale->created_at)) {
                    $reason = 'More than 5 minutes';
                }
                if ($reason === null && ReturnRecord::where('tenant_id', $tenantId)
                    ->where('source_type', 'package_sale')
                    ->where('source_id', $sale->id)
                    ->exists()
                ) {
                    $reason = 'Already returned';
                }

                return [
                    'id' => $sale->id,
                    'amount' => (int)$sale->amount,
                    'payment_method' => $sale->payment_method,
                    'created_at' => $sale->created_at,
                    'package_id' => $sale->package_id,
                    'eligible' => $reason === null,
                    'reason' => $reason,
                ];
            })
            ->filter(fn($x) => $x['eligible'] === true)
            ->values();

        return response()->json([
            'data' => [
                'shift' => $shift ? $shift->only(['id','opened_at','closed_at']) : null,
                'topups' => $topups,
                'packages' => $packages,
            ],
        ]);
    }

    public function store(Request $request, int $id)
    {
        $operator = $request->user();
        $tenantId = $operator->tenant_id;

        $data = $request->validate([
            'type' => 'required|string|in:topup,package',
            'source_id' => 'required|integer',
        ]);

        $client = Client::where('tenant_id', $tenantId)
            ->where(function ($q) use ($id) {
                $q->where('id', $id)
                    ->orWhere('login', (string)$id)
                    ->orWhere('account_id', (string)$id);
            })
            ->firstOrFail();
        $shift = $this->currentShift($tenantId);

        if (!$shift) {
            throw ValidationException::withMessages(['shift' => 'Shift is not open']);
        }

        return DB::transaction(function () use ($data, $client, $operator, $tenantId, $shift) {
            if ($data['type'] === 'topup') {
                $tx = ClientTransaction::query()
                    ->where('tenant_id', $tenantId)
                    ->where('client_id', $client->id)
                    ->where('id', (int)$data['source_id'])
                    ->lockForUpdate()
                    ->firstOrFail();

                if (!$this->isTopupType((string)$tx->type)) {
                    throw ValidationException::withMessages(['type' => 'Invalid topup transaction']);
                }

                if ((int)$tx->shift_id !== (int)$shift->id) {
                    throw ValidationException::withMessages(['shift' => 'Different shift']);
                }
                if (!$this->withinFiveMinutes($tx->created_at)) {
                    throw ValidationException::withMessages(['time' => 'Return window expired']);
                }
                if (ReturnRecord::where('tenant_id', $tenantId)
                    ->where('source_type', 'client_transaction')
                    ->where('source_id', $tx->id)
                    ->exists()
                ) {
                    throw ValidationException::withMessages(['return' => 'Already returned']);
                }
                if (str_contains(strtolower((string)$tx->payment_method), 'card')) {
                    throw ValidationException::withMessages(['payment_method' => 'Card topups cannot be returned']);
                }

                if ((int)$client->balance < (int)$tx->amount) {
                    throw ValidationException::withMessages(['balance' => 'Insufficient balance']);
                }
                if ((int)$client->bonus < (int)$tx->bonus_amount) {
                    throw ValidationException::withMessages(['bonus' => 'Insufficient bonus']);
                }

                $client->balance = (int)$client->balance - (int)$tx->amount;
                if ((int)$tx->bonus_amount > 0) {
                    $client->bonus = (int)$client->bonus - (int)$tx->bonus_amount;
                }
                $client->lifetime_topup = max(0, (int)$client->lifetime_topup - (int)$tx->amount);
                $client->save();

                ClientTransaction::create([
                    'tenant_id' => $tenantId,
                    'client_id' => $client->id,
                    'operator_id' => $operator->id,
                    'shift_id' => $shift->id,
                    'type' => 'refund',
                    'amount' => -1 * (int)$tx->amount,
                    'bonus_amount' => -1 * (int)$tx->bonus_amount,
                    'payment_method' => $tx->payment_method,
                    'comment' => 'Topup return',
                ]);

                if ($tx->payment_method === 'cash') {
                    $shift->update(['topups_cash_total' => max(0, (int)$shift->topups_cash_total - (int)$tx->amount)]);
                } elseif ($tx->payment_method === 'card') {
                    $shift->update(['topups_card_total' => max(0, (int)$shift->topups_card_total - (int)$tx->amount)]);
                }

                $ret = ReturnRecord::create([
                    'tenant_id' => $tenantId,
                    'client_id' => $client->id,
                    'operator_id' => $operator->id,
                    'shift_id' => $shift->id,
                    'type' => 'topup',
                    'amount' => (int)$tx->amount,
                    'payment_method' => $tx->payment_method,
                    'source_type' => 'client_transaction',
                    'source_id' => $tx->id,
                ]);

                return response()->json(['data' => ['return' => $ret]]);
            }

            // package return
            $sale = PackageSale::query()
                ->where('tenant_id', $tenantId)
                ->where('client_id', $client->id)
                ->where('id', (int)$data['source_id'])
                ->lockForUpdate()
                ->firstOrFail();

            if ($sale->payment_method === 'cash' || $sale->payment_method === 'card') {
                if ((int)$sale->shift_id !== (int)$shift->id) {
                    throw ValidationException::withMessages(['shift' => 'Different shift']);
                }
            } else {
                if ($sale->created_at < $shift->opened_at) {
                    throw ValidationException::withMessages(['shift' => 'Different shift']);
                }
            }

            if (!$this->withinFiveMinutes($sale->created_at)) {
                throw ValidationException::withMessages(['time' => 'Return window expired']);
            }
            if (ReturnRecord::where('tenant_id', $tenantId)
                ->where('source_type', 'package_sale')
                ->where('source_id', $sale->id)
                ->exists()
            ) {
                throw ValidationException::withMessages(['return' => 'Already returned']);
            }

            if ($sale->payment_method === 'balance') {
                $client->increment('balance', (int)$sale->amount);
            }

            $refundAmount = $sale->payment_method === 'balance'
                ? (int)$sale->amount
                : -1 * (int)$sale->amount;

            ClientTransaction::create([
                'tenant_id' => $tenantId,
                'client_id' => $client->id,
                'operator_id' => $operator->id,
                'shift_id' => $shift->id,
                'type' => 'refund',
                'amount' => $refundAmount,
                'bonus_amount' => 0,
                'payment_method' => $sale->payment_method,
                'comment' => $sale->payment_method === 'balance' ? 'Package return (balance)' : 'Package return',
            ]);

            if ($sale->payment_method === 'cash') {
                $shift->update(['packages_cash_total' => max(0, (int)$shift->packages_cash_total - (int)$sale->amount)]);
            } elseif ($sale->payment_method === 'card') {
                $shift->update(['packages_card_total' => max(0, (int)$shift->packages_card_total - (int)$sale->amount)]);
            }

            $clientPackageId = $sale->meta['client_package_id'] ?? null;
            if ($clientPackageId) {
                DB::table('client_packages')
                    ->where('tenant_id', $tenantId)
                    ->where('id', $clientPackageId)
                    ->update([
                        'status' => 'refunded',
                        'remaining_min' => 0,
                        'expires_at' => now(),
                        'updated_at' => now(),
                    ]);
            }

            $ret = ReturnRecord::create([
                'tenant_id' => $tenantId,
                'client_id' => $client->id,
                'operator_id' => $operator->id,
                'shift_id' => $shift->id,
                'type' => 'package',
                'amount' => (int)$sale->amount,
                'payment_method' => $sale->payment_method,
                'source_type' => 'package_sale',
                'source_id' => $sale->id,
                'meta' => [
                    'package_id' => $sale->package_id,
                    'client_package_id' => $clientPackageId,
                ],
            ]);

            return response()->json(['data' => ['return' => $ret]]);
        });
    }
}
