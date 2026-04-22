<?php

namespace App\Services;

use App\Enums\PaymentMethod;
use App\Models\Client;
use App\Models\ClientTransaction;
use App\Models\PackageSale;
use App\Models\ReturnRecord;
use App\Models\Shift;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ReturnService
{
    private const RETURN_WINDOW_SECONDS = 300;

    public function __construct(
        private readonly ShiftService $shifts,
    ) {
    }

    public function index(int $tenantId, ?int $shiftId = null, int $perPage = 20): LengthAwarePaginator
    {
        $query = ReturnRecord::query()
            ->where('tenant_id', $tenantId)
            ->with([
                'client:id,login,phone',
                'operator:id,login,role',
                'shift:id,opened_at,closed_at',
            ])
            ->orderByDesc('id');

        if ($shiftId !== null) {
            $query->where('shift_id', $shiftId);
        }

        return $query->paginate($perPage);
    }

    public function options(int $tenantId, int $clientLookup): array
    {
        $client = $this->resolveClient($tenantId, $clientLookup);
        $shift = $this->currentShift($tenantId);

        $topups = ClientTransaction::query()
            ->where('tenant_id', $tenantId)
            ->where('client_id', $client->id)
            ->orderByDesc('id')
            ->limit(30)
            ->get()
            ->filter(fn(ClientTransaction $tx) => $this->isTopupType((string) $tx->type))
            ->map(fn(ClientTransaction $tx) => $this->buildTopupOption($tenantId, $client, $shift, $tx))
            ->filter(fn(array $item) => $item['eligible'] === true)
            ->values()
            ->all();

        $packages = PackageSale::query()
            ->where('tenant_id', $tenantId)
            ->where('client_id', $client->id)
            ->orderByDesc('id')
            ->limit(30)
            ->get()
            ->map(fn(PackageSale $sale) => $this->buildPackageOption($tenantId, $shift, $sale))
            ->filter(fn(array $item) => $item['eligible'] === true)
            ->values()
            ->all();

        return [
            'shift' => $shift ? $shift->only(['id', 'opened_at', 'closed_at']) : null,
            'topups' => $topups,
            'packages' => $packages,
        ];
    }

    public function store(
        int $tenantId,
        int $clientLookup,
        int $operatorId,
        string $type,
        int $sourceId,
    ): ReturnRecord {
        $client = $this->resolveClient($tenantId, $clientLookup);
        $shift = $this->currentShift($tenantId);

        if (!$shift) {
            throw ValidationException::withMessages(['shift' => 'Shift is not open']);
        }

        return DB::transaction(function () use ($tenantId, $client, $shift, $operatorId, $type, $sourceId) {
            $lockedClient = Client::query()
                ->where('tenant_id', $tenantId)
                ->whereKey($client->id)
                ->lockForUpdate()
                ->firstOrFail();

            $lockedShift = Shift::query()
                ->where('tenant_id', $tenantId)
                ->whereKey($shift->id)
                ->lockForUpdate()
                ->firstOrFail();

            return match ($type) {
                'topup' => $this->refundTopup($tenantId, $lockedClient, $lockedShift, $operatorId, $sourceId),
                'package' => $this->refundPackage($tenantId, $lockedClient, $lockedShift, $operatorId, $sourceId),
                default => throw ValidationException::withMessages(['type' => 'Invalid return type']),
            };
        });
    }

    private function refundTopup(
        int $tenantId,
        Client $client,
        Shift $shift,
        int $operatorId,
        int $sourceId,
    ): ReturnRecord {
        $tx = ClientTransaction::query()
            ->where('tenant_id', $tenantId)
            ->where('client_id', $client->id)
            ->whereKey($sourceId)
            ->lockForUpdate()
            ->firstOrFail();

        if (!$this->isTopupType((string) $tx->type)) {
            throw ValidationException::withMessages(['type' => 'Invalid topup transaction']);
        }

        $reason = $this->topupReason($tenantId, $client, $shift, $tx);
        if ($reason !== null) {
            $this->throwTopupReason($reason);
        }

        $client->balance = (int) $client->balance - (int) $tx->amount;
        if ((int) $tx->bonus_amount > 0) {
            $client->bonus = (int) $client->bonus - (int) $tx->bonus_amount;
        }
        $client->lifetime_topup = max(0, (int) $client->lifetime_topup - (int) $tx->amount);
        $client->save();

        ClientTransaction::query()->create([
            'tenant_id' => $tenantId,
            'client_id' => $client->id,
            'operator_id' => $operatorId,
            'shift_id' => $shift->id,
            'type' => 'refund',
            'amount' => -1 * (int) $tx->amount,
            'bonus_amount' => -1 * (int) $tx->bonus_amount,
            'payment_method' => $tx->payment_method,
            'comment' => 'Topup return',
        ]);

        $this->decrementShiftSaleTotal($shift, 'topups', PaymentMethod::fromNullable($tx->payment_method), (int) $tx->amount);

        return ReturnRecord::query()->create([
            'tenant_id' => $tenantId,
            'client_id' => $client->id,
            'operator_id' => $operatorId,
            'shift_id' => $shift->id,
            'type' => 'topup',
            'amount' => (int) $tx->amount,
            'payment_method' => $tx->payment_method,
            'source_type' => 'client_transaction',
            'source_id' => $tx->id,
        ]);
    }

    private function refundPackage(
        int $tenantId,
        Client $client,
        Shift $shift,
        int $operatorId,
        int $sourceId,
    ): ReturnRecord {
        $sale = PackageSale::query()
            ->where('tenant_id', $tenantId)
            ->where('client_id', $client->id)
            ->whereKey($sourceId)
            ->lockForUpdate()
            ->firstOrFail();

        $reason = $this->packageReason($tenantId, $shift, $sale);
        if ($reason !== null) {
            $this->throwPackageReason($reason);
        }

        $paymentMethod = PaymentMethod::fromNullable($sale->payment_method);
        if ($paymentMethod?->isBalance()) {
            $client->increment('balance', (int) $sale->amount);
        }

        $refundAmount = $paymentMethod?->isBalance()
            ? (int) $sale->amount
            : -1 * (int) $sale->amount;

        ClientTransaction::query()->create([
            'tenant_id' => $tenantId,
            'client_id' => $client->id,
            'operator_id' => $operatorId,
            'shift_id' => $shift->id,
            'type' => 'refund',
            'amount' => $refundAmount,
            'bonus_amount' => 0,
            'payment_method' => $sale->payment_method,
            'comment' => $paymentMethod?->isBalance() ? 'Package return (balance)' : 'Package return',
        ]);

        $this->decrementShiftSaleTotal($shift, 'packages', $paymentMethod, (int) $sale->amount);

        $clientPackageId = is_array($sale->meta) ? ($sale->meta['client_package_id'] ?? null) : null;
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

        return ReturnRecord::query()->create([
            'tenant_id' => $tenantId,
            'client_id' => $client->id,
            'operator_id' => $operatorId,
            'shift_id' => $shift->id,
            'type' => 'package',
            'amount' => (int) $sale->amount,
            'payment_method' => $sale->payment_method,
            'source_type' => 'package_sale',
            'source_id' => $sale->id,
            'meta' => [
                'package_id' => $sale->package_id,
                'client_package_id' => $clientPackageId,
            ],
        ]);
    }

    private function buildTopupOption(int $tenantId, Client $client, ?Shift $shift, ClientTransaction $tx): array
    {
        $reason = $this->topupReason($tenantId, $client, $shift, $tx);

        return [
            'id' => (int) $tx->id,
            'amount' => (int) $tx->amount,
            'bonus_amount' => (int) $tx->bonus_amount,
            'payment_method' => $tx->payment_method,
            'created_at' => $tx->created_at,
            'eligible' => $reason === null,
            'reason' => $reason,
        ];
    }

    private function buildPackageOption(int $tenantId, ?Shift $shift, PackageSale $sale): array
    {
        $reason = $this->packageReason($tenantId, $shift, $sale);

        return [
            'id' => (int) $sale->id,
            'amount' => (int) $sale->amount,
            'payment_method' => $sale->payment_method,
            'created_at' => $sale->created_at,
            'package_id' => $sale->package_id,
            'eligible' => $reason === null,
            'reason' => $reason,
        ];
    }

    private function topupReason(int $tenantId, Client $client, ?Shift $shift, ClientTransaction $tx): ?string
    {
        if (!$shift) {
            return 'Shift closed';
        }

        if ((int) $tx->shift_id !== (int) $shift->id) {
            return 'Different shift';
        }

        if (!$this->withinReturnWindow($tx->created_at)) {
            return 'More than 5 minutes';
        }

        if ($this->alreadyReturned($tenantId, 'client_transaction', (int) $tx->id)) {
            return 'Already returned';
        }

        $paymentMethod = PaymentMethod::fromNullable($tx->payment_method);
        if ($paymentMethod?->isCard()) {
            return 'Card topups cannot be returned';
        }

        if ((int) $client->balance < (int) $tx->amount) {
            return 'Insufficient balance';
        }

        if ((int) $client->bonus < (int) $tx->bonus_amount) {
            return 'Insufficient bonus';
        }

        return null;
    }

    private function packageReason(int $tenantId, ?Shift $shift, PackageSale $sale): ?string
    {
        if (!$shift) {
            return 'Shift closed';
        }

        $paymentMethod = PaymentMethod::fromNullable($sale->payment_method);
        if ($paymentMethod?->isCash() || $paymentMethod?->isCard()) {
            if ((int) $sale->shift_id !== (int) $shift->id) {
                return 'Different shift';
            }
        } elseif ($sale->created_at < $shift->opened_at) {
            return 'Different shift';
        }

        if (!$this->withinReturnWindow($sale->created_at)) {
            return 'More than 5 minutes';
        }

        if ($this->alreadyReturned($tenantId, 'package_sale', (int) $sale->id)) {
            return 'Already returned';
        }

        return null;
    }

    private function throwTopupReason(string $reason): never
    {
        $field = match ($reason) {
            'Different shift', 'Shift closed' => 'shift',
            'More than 5 minutes' => 'time',
            'Already returned' => 'return',
            'Card topups cannot be returned' => 'payment_method',
            'Insufficient balance' => 'balance',
            'Insufficient bonus' => 'bonus',
            default => 'return',
        };

        $message = match ($reason) {
            'Shift closed' => 'Shift is not open',
            'More than 5 minutes' => 'Return window expired',
            default => $reason,
        };

        throw ValidationException::withMessages([$field => $message]);
    }

    private function throwPackageReason(string $reason): never
    {
        $field = match ($reason) {
            'Different shift', 'Shift closed' => 'shift',
            'More than 5 minutes' => 'time',
            'Already returned' => 'return',
            default => 'return',
        };

        $message = match ($reason) {
            'Shift closed' => 'Shift is not open',
            'More than 5 minutes' => 'Return window expired',
            default => $reason,
        };

        throw ValidationException::withMessages([$field => $message]);
    }

    private function resolveClient(int $tenantId, int $lookup): Client
    {
        return Client::query()
            ->where('tenant_id', $tenantId)
            ->where(function ($query) use ($lookup) {
                $stringLookup = (string) $lookup;

                $query->where('id', $lookup)
                    ->orWhere('login', $stringLookup)
                    ->orWhere('account_id', $stringLookup);
            })
            ->firstOrFail();
    }

    private function currentShift(int $tenantId): ?Shift
    {
        return $this->shifts->currentShift($tenantId);
    }

    private function withinReturnWindow($createdAt): bool
    {
        return now()->diffInSeconds($createdAt) <= self::RETURN_WINDOW_SECONDS;
    }

    private function alreadyReturned(int $tenantId, string $sourceType, int $sourceId): bool
    {
        return ReturnRecord::query()
            ->where('tenant_id', $tenantId)
            ->where('source_type', $sourceType)
            ->where('source_id', $sourceId)
            ->exists();
    }

    private function isTopupType(string $type): bool
    {
        $normalized = strtolower($type);

        return str_contains($normalized, 'topup')
            || str_contains($normalized, 'deposit')
            || str_contains($normalized, 'pay');
    }

    private function decrementShiftSaleTotal(
        Shift $shift,
        string $prefix,
        ?PaymentMethod $paymentMethod,
        int $amount,
    ): void {
        if (!$paymentMethod || $paymentMethod->isBalance()) {
            return;
        }

        $column = match ($paymentMethod) {
            PaymentMethod::Cash => $prefix . '_cash_total',
            PaymentMethod::Card => $prefix . '_card_total',
            default => null,
        };

        if ($column === null) {
            return;
        }

        $shift->update([
            $column => max(0, (int) ($shift->{$column} ?? 0) - $amount),
        ]);
    }
}
