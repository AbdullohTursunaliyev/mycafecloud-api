<?php

namespace App\Services;

use App\Enums\PaymentMethod;
use App\Models\Client;
use App\Models\ClientPackage;
use App\Models\ClientTransaction;
use App\Models\Operator;
use App\Models\Package;
use App\Models\PackageSale;
use App\Models\Shift;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ClientPackageService
{
    public function attach(
        int $tenantId,
        Operator $operator,
        int $clientId,
        int $packageId,
        string $paymentMethodValue,
    ): array {
        $client = Client::query()
            ->where('tenant_id', $tenantId)
            ->findOrFail($clientId);

        $package = Package::query()
            ->where('tenant_id', $tenantId)
            ->where('is_active', true)
            ->findOrFail($packageId);

        $paymentMethod = PaymentMethod::fromNullable($paymentMethodValue);
        if (!$paymentMethod) {
            throw ValidationException::withMessages([
                'payment_method' => 'Invalid payment method',
            ]);
        }

        return DB::transaction(function () use ($tenantId, $operator, $client, $package, $paymentMethod) {
            $lockedClient = Client::query()
                ->where('tenant_id', $tenantId)
                ->whereKey($client->id)
                ->lockForUpdate()
                ->firstOrFail();

            $activePackage = ClientPackage::query()
                ->join('packages', 'packages.id', '=', 'client_packages.package_id')
                ->where('client_packages.tenant_id', $tenantId)
                ->where('client_packages.client_id', $lockedClient->id)
                ->where('client_packages.status', 'active')
                ->where('client_packages.remaining_min', '>', 0)
                ->where(function ($query) {
                    $query->whereNull('client_packages.expires_at')
                        ->orWhere('client_packages.expires_at', '>', now());
                })
                ->where('packages.zone', $package->zone)
                ->lockForUpdate()
                ->select('client_packages.*')
                ->latest('client_packages.id')
                ->first();

            if ($activePackage) {
                throw ValidationException::withMessages([
                    'package_id' => 'У клиента уже есть активный пакет на эту зону. Сначала дождитесь окончания пакета, потом можно добавить новый.',
                ]);
            }

            $amount = (int) $package->price;
            $shift = null;

            if ($paymentMethod->isBalance()) {
                if ((int) $lockedClient->balance < $amount) {
                    throw ValidationException::withMessages([
                        'balance' => 'Недостаточно средств на балансе клиента.',
                    ]);
                }

                $lockedClient->balance = (int) $lockedClient->balance - $amount;
                $lockedClient->save();
            } else {
                $shift = Shift::query()
                    ->where('tenant_id', $tenantId)
                    ->whereNull('closed_at')
                    ->lockForUpdate()
                    ->latest('id')
                    ->first();

                if (!$shift) {
                    throw ValidationException::withMessages([
                        'shift' => 'Смена закрыта. Нельзя оформить пакет через кассу.',
                    ]);
                }

                $this->incrementShiftPackageTotal($shift, $paymentMethod, $amount);
            }

            $clientPackage = ClientPackage::query()->create([
                'tenant_id' => $tenantId,
                'client_id' => $lockedClient->id,
                'package_id' => $package->id,
                'remaining_min' => (int) $package->duration_min,
                'expires_at' => null,
                'status' => 'active',
            ]);

            ClientTransaction::query()->create([
                'tenant_id' => $tenantId,
                'client_id' => $lockedClient->id,
                'operator_id' => $operator->id,
                'shift_id' => $shift?->id,
                'type' => 'package',
                'amount' => $amount,
                'bonus_amount' => 0,
                'payment_method' => $paymentMethod->value,
                'comment' => 'Покупка пакета: ' . $package->name,
                'promotion_id' => null,
            ]);

            $sale = PackageSale::query()->create([
                'tenant_id' => $tenantId,
                'client_id' => $lockedClient->id,
                'package_id' => $package->id,
                'payment_method' => $paymentMethod->value,
                'shift_id' => $shift?->id,
                'operator_id' => $operator->id,
                'amount' => $amount,
                'meta' => [
                    'client_package_id' => $clientPackage->id,
                    'package_name' => $package->name,
                    'zone' => $package->zone,
                    'duration_min' => (int) $package->duration_min,
                ],
            ]);

            $lockedClient->refresh();

            return [
                'client' => $lockedClient,
                'client_package' => $clientPackage,
                'package' => $package,
                'package_sale' => $sale,
                'payment_method' => $paymentMethod->value,
                'amount' => $amount,
                'shift_id' => $shift?->id,
            ];
        });
    }

    private function incrementShiftPackageTotal(Shift $shift, PaymentMethod $paymentMethod, int $amount): void
    {
        $column = match ($paymentMethod) {
            PaymentMethod::Cash => 'packages_cash_total',
            PaymentMethod::Card => 'packages_card_total',
            default => null,
        };

        if ($column === null) {
            return;
        }

        $shift->update([
            $column => (int) ($shift->{$column} ?? 0) + $amount,
        ]);
    }
}
