<?php

namespace App\Services;

use App\Models\Client;
use App\Models\ClientPackage;
use App\Models\ClientTransaction;
use App\Models\Shift;
use Carbon\Carbon;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class ClientAdminService
{
    public function __construct(
        private readonly ClientTopupService $topups,
    ) {
    }

    public function paginate(int $tenantId, array $filters): LengthAwarePaginator
    {
        $query = Client::query()
            ->where('tenant_id', $tenantId)
            ->orderByDesc('id');

        if (!empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (!empty($filters['search'])) {
            $search = (string) $filters['search'];
            $query->where(function ($builder) use ($search) {
                $builder->where('account_id', 'ILIKE', "%{$search}%")
                    ->orWhere('login', 'ILIKE', "%{$search}%")
                    ->orWhere('phone', 'ILIKE', "%{$search}%")
                    ->orWhere('username', 'ILIKE', "%{$search}%")
                    ->orWhere('name', 'ILIKE', "%{$search}%");
            });
        }

        return $query->paginate(20);
    }

    public function create(int $tenantId, array $payload): Client
    {
        if (empty($payload['account_id']) && empty($payload['login'])) {
            throw ValidationException::withMessages([
                'account_id' => 'Укажите account_id или login',
            ]);
        }

        $name = $payload['name'] ?? null;
        $username = $payload['username'] ?? $name ?? null;

        return Client::query()->create([
            'tenant_id' => $tenantId,
            'account_id' => $payload['account_id'] ?? null,
            'login' => $payload['login'] ?? null,
            'password' => isset($payload['password']) ? Hash::make($payload['password']) : null,
            'phone' => $payload['phone'] ?? null,
            'name' => $name,
            'username' => $username,
            'balance' => 0,
            'bonus' => 0,
            'status' => 'active',
        ]);
    }

    public function topup(
        int $tenantId,
        int $operatorId,
        int $clientId,
        array $payload,
    ): array {
        $amount = (int) $payload['amount'];
        $paymentMethod = (string) $payload['payment_method'];
        $manualBonus = (int) ($payload['bonus_amount'] ?? 0);

        return DB::transaction(function () use ($tenantId, $operatorId, $clientId, $amount, $paymentMethod, $manualBonus, $payload) {
            $client = Client::query()
                ->where('tenant_id', $tenantId)
                ->where('id', $clientId)
                ->lockForUpdate()
                ->firstOrFail();

            $result = $this->topups->applyTopup(
                $client,
                $tenantId,
                $operatorId,
                $this->requireOpenShift($tenantId),
                $amount,
                $paymentMethod,
                $manualBonus,
                $payload['comment'] ?? null,
                now(),
            );

            /** @var Client $freshClient */
            $freshClient = $result['client'];

            return [
                'balance' => $freshClient->balance,
                'bonus' => $freshClient->bonus,
                'tier_id' => $freshClient->tier_id,
                'lifetime_topup' => $freshClient->lifetime_topup,
            ];
        });
    }

    public function bulkTopup(
        int $tenantId,
        int $operatorId,
        array $payload,
    ): array {
        $clientIds = array_values(array_unique((array) ($payload['client_ids'] ?? [])));
        if ($clientIds === []) {
            throw ValidationException::withMessages([
                'client_ids' => 'Clients list is empty',
            ]);
        }

        $amount = (int) $payload['amount'];
        $paymentMethod = (string) $payload['payment_method'];
        $manualBonus = (int) ($payload['bonus_amount'] ?? 0);
        $shift = $this->requireOpenShift($tenantId);
        $items = [];

        DB::transaction(function () use ($tenantId, $operatorId, $clientIds, $amount, $paymentMethod, $manualBonus, $payload, $shift, &$items) {
            $now = now();

            foreach ($clientIds as $clientId) {
                $client = Client::query()
                    ->where('tenant_id', $tenantId)
                    ->where('id', (int) $clientId)
                    ->lockForUpdate()
                    ->first();

                if (!$client) {
                    throw ValidationException::withMessages([
                        'client_ids' => "Client not found: {$clientId}",
                    ]);
                }

                $result = $this->topups->applyTopup(
                    $client,
                    $tenantId,
                    $operatorId,
                    $shift,
                    $amount,
                    $paymentMethod,
                    $manualBonus,
                    $payload['comment'] ?? null,
                    $now,
                );

                /** @var Client $freshClient */
                $freshClient = $result['client'];
                $items[] = [
                    'id' => $freshClient->id,
                    'balance' => $freshClient->balance,
                    'bonus' => $freshClient->bonus,
                    'tier_id' => $freshClient->tier_id,
                    'lifetime_topup' => $freshClient->lifetime_topup,
                ];
            }
        });

        return [
            'count' => count($items),
            'items' => $items,
        ];
    }

    public function history(int $tenantId, int $clientId, ?string $date): LengthAwarePaginator
    {
        $client = Client::query()
            ->where('tenant_id', $tenantId)
            ->findOrFail($clientId);

        $query = ClientTransaction::query()
            ->where('tenant_id', $tenantId)
            ->where('client_id', $client->id)
            ->orderByDesc('id');

        if ($date !== null) {
            try {
                $query->whereDate('created_at', Carbon::parse($date)->toDateString());
            } catch (\Throwable) {
            }
        }

        return $query->paginate(20);
    }

    public function sessions(int $tenantId, int $clientId, ?string $date): LengthAwarePaginator
    {
        $client = Client::query()
            ->where('tenant_id', $tenantId)
            ->findOrFail($clientId);

        $query = \App\Models\Session::query()
            ->where('tenant_id', $tenantId)
            ->where('client_id', $client->id)
            ->with([
                'pc:id,code,zone',
                'tariff:id,name,price_per_hour',
            ])
            ->orderByDesc('id');

        if ($date !== null) {
            try {
                $query->whereDate('started_at', Carbon::parse($date)->toDateString());
            } catch (\Throwable) {
            }
        }

        return $query->paginate(20);
    }

    public function packages(int $tenantId, int $clientId): array
    {
        $client = Client::query()
            ->where('tenant_id', $tenantId)
            ->findOrFail($clientId);

        return ClientPackage::query()
            ->with('package')
            ->where('tenant_id', $tenantId)
            ->where('client_id', $client->id)
            ->orderByDesc('id')
            ->get()
            ->map(function (ClientPackage $clientPackage) {
                return [
                    'id' => $clientPackage->id,
                    'package_id' => $clientPackage->package_id,
                    'status' => $clientPackage->status,
                    'remaining_min' => (int) $clientPackage->remaining_min,
                    'expires_at' => $clientPackage->expires_at,
                    'created_at' => $clientPackage->created_at,
                    'package' => $clientPackage->package ? [
                        'id' => $clientPackage->package->id,
                        'name' => $clientPackage->package->name,
                        'duration_min' => (int) $clientPackage->package->duration_min,
                        'price' => (int) $clientPackage->package->price,
                        'zone' => $clientPackage->package->zone,
                    ] : null,
                ];
            })
            ->values()
            ->all();
    }

    private function requireOpenShift(int $tenantId): Shift
    {
        $shift = Shift::query()
            ->where('tenant_id', $tenantId)
            ->whereNull('closed_at')
            ->latest('id')
            ->first();

        if ($shift) {
            return $shift;
        }

        throw ValidationException::withMessages([
            'shift' => 'Смена не открыта',
        ]);
    }
}
