<?php

namespace App\Actions\Session;

use App\Models\Booking;
use App\Models\Session;
use App\Services\ClientSessionService;
use App\Services\SessionStartService;
use Illuminate\Validation\ValidationException;

class StartOperatorSessionAction
{
    public function __construct(
        private readonly ClientSessionService $sessions,
        private readonly SessionStartService $startService,
    ) {
    }

    public function execute(
        int $tenantId,
        int $operatorId,
        int $pcId,
        int $clientId,
        int $tariffId,
    ): Session {
        $pc = $this->startService->resolvePc($tenantId, $pcId);
        $client = $this->startService->resolveClient($tenantId, $clientId);
        $tariff = $this->startService->resolveTariff($tenantId, $tariffId);

        $this->startService->ensureClientCanStart($client, 'client_id');
        $this->startService->ensureTariffMatchesPc($pc, $tariff, 'tariff_id');
        $this->startService->ensureWalletCanStart($client, $tariff, 'balance');

        $now = now();
        $currentBooking = Booking::query()
            ->where('tenant_id', $tenantId)
            ->where('pc_id', $pc->id)
            ->where('status', 'active')
            ->where('start_at', '<=', $now)
            ->where('end_at', '>=', $now)
            ->first();

        if ($currentBooking && (int) $currentBooking->client_id !== (int) $client->id) {
            throw ValidationException::withMessages([
                'booking' => 'ПК забронирован для другого клиента',
            ]);
        }

        return $this->sessions->startOperatorSession(
            $tenantId,
            $operatorId,
            $pc,
            $client,
            $tariff,
            $currentBooking,
            $now,
        );
    }
}
