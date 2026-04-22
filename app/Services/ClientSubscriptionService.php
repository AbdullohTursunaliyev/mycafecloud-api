<?php

namespace App\Services;

use App\Models\Client;
use App\Models\ClientSubscription;
use App\Models\ClientTransaction;
use App\Models\Operator;
use App\Models\Shift;
use App\Models\SubscriptionPlan;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ClientSubscriptionService
{
    public function expireOld(int $tenantId, int $clientId): void
    {
        ClientSubscription::query()
            ->where('tenant_id', $tenantId)
            ->where('client_id', $clientId)
            ->where('status', 'active')
            ->whereNotNull('ends_at')
            ->where('ends_at', '<=', now())
            ->update([
                'status' => 'expired',
                'updated_at' => now(),
            ]);
    }

    public function subscribe(
        int $tenantId,
        Operator $operator,
        Client $client,
        SubscriptionPlan $plan,
        string $paymentMethod,
        ?string $comment = null,
    ): ClientSubscription {
        $this->expireOld($tenantId, (int) $client->id);

        try {
            return DB::transaction(function () use ($tenantId, $operator, $client, $plan, $paymentMethod, $comment) {
                $this->lockClientZone($tenantId, (int) $client->id, (int) $plan->zone_id);

                $lockedClient = Client::query()
                    ->where('tenant_id', $tenantId)
                    ->lockForUpdate()
                    ->findOrFail($client->id);

                $existing = ClientSubscription::query()
                    ->where('tenant_id', $tenantId)
                    ->where('client_id', $lockedClient->id)
                    ->where('zone_id', $plan->zone_id)
                    ->where('status', 'active')
                    ->lockForUpdate()
                    ->latest('id')
                    ->first();

                if ($existing) {
                    throw ValidationException::withMessages([
                        'subscription_plan_id' => 'У клиента уже есть активная подписка в этой зоне. Дождитесь окончания.',
                    ]);
                }

                $shift = Shift::query()
                    ->where('tenant_id', $tenantId)
                    ->whereNull('closed_at')
                    ->lockForUpdate()
                    ->latest('id')
                    ->first();

                if (!$shift) {
                    throw ValidationException::withMessages([
                        'shift' => 'Смена не открыта',
                    ]);
                }

                $amount = (int) $plan->price;

                if ($paymentMethod === 'balance') {
                    if ((int) $lockedClient->balance < $amount) {
                        throw ValidationException::withMessages([
                            'balance' => 'Недостаточно средств на балансе клиента',
                        ]);
                    }

                    $lockedClient->decrement('balance', $amount);
                }

                $starts = now();
                $ends = now()->addDays((int) $plan->duration_days);

                $subscription = ClientSubscription::query()->create([
                    'tenant_id' => $tenantId,
                    'client_id' => $lockedClient->id,
                    'subscription_plan_id' => $plan->id,
                    'zone_id' => $plan->zone_id,
                    'status' => 'active',
                    'starts_at' => $starts,
                    'ends_at' => $ends,
                    'payment_method' => $paymentMethod,
                    'shift_id' => $shift->id,
                    'operator_id' => $operator->id,
                    'amount' => $amount,
                    'meta' => [
                        'plan_name' => $plan->name,
                        'duration_days' => (int) $plan->duration_days,
                    ],
                ]);

                ClientTransaction::query()->create([
                    'tenant_id' => $tenantId,
                    'client_id' => $lockedClient->id,
                    'operator_id' => $operator->id,
                    'shift_id' => $shift->id,
                    'type' => 'subscription',
                    'amount' => $amount,
                    'bonus_amount' => 0,
                    'payment_method' => $paymentMethod,
                    'comment' => $comment,
                    'meta' => [
                        'subscription_id' => $subscription->id,
                        'subscription_plan_id' => $plan->id,
                        'zone_id' => $plan->zone_id,
                        'plan_name' => $plan->name,
                        'duration_days' => (int) $plan->duration_days,
                    ],
                ]);

                return $subscription;
            });
        } catch (QueryException $exception) {
            if ($this->isActiveSubscriptionUniqueViolation($exception)) {
                throw ValidationException::withMessages([
                    'subscription_plan_id' => 'У клиента уже есть активная подписка в этой зоне. Дождитесь окончания.',
                ]);
            }

            throw $exception;
        }
    }

    private function lockClientZone(int $tenantId, int $clientId, int $zoneId): void
    {
        DB::select('SELECT pg_advisory_xact_lock(?)', [$tenantId * 1000000 + $clientId * 1000 + $zoneId]);
    }

    private function isActiveSubscriptionUniqueViolation(QueryException $exception): bool
    {
        $sqlState = $exception->errorInfo[0] ?? null;

        return $sqlState === '23505'
            && str_contains($exception->getMessage(), 'client_subscriptions_one_active_per_zone_idx');
    }
}
