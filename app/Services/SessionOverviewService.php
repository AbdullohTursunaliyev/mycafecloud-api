<?php

namespace App\Services;

use App\Models\Session;
use Illuminate\Support\Collection;

class SessionOverviewService
{
    public function __construct(
        private readonly ClientSessionService $sessions,
    ) {
    }

    public function active(int $tenantId): Collection
    {
        return Session::query()
            ->where('tenant_id', $tenantId)
            ->where('status', 'active')
            ->with(['pc.zoneRel', 'tariff', 'client', 'clientPackage'])
            ->orderByDesc('started_at')
            ->get()
            ->map(function (Session $session) {
                $pc = $session->pc;
                $client = $session->client;
                $tariff = $session->tariff;
                $time = $pc && $client
                    ? $this->sessions->resolveSessionTime($session, $client, $pc)
                    : [
                        'seconds_left' => 0,
                        'from' => 'balance',
                        'rate_per_hour' => (int) ($tariff?->price_per_hour ?? 0),
                    ];

                return [
                    'id' => (int) $session->id,
                    'pc' => $pc ? [
                        'id' => (int) $pc->id,
                        'code' => (string) $pc->code,
                        'zone' => (string) ($time['zone_name'] ?? $pc->zone ?? ''),
                    ] : null,
                    'client' => $client ? [
                        'id' => (int) $client->id,
                        'account_id' => $client->account_id,
                        'login' => $client->login,
                        'phone' => $client->phone,
                        'balance' => $client->balance,
                        'bonus' => $client->bonus,
                    ] : null,
                    'tariff' => $tariff ? [
                        'id' => (int) $tariff->id,
                        'name' => (string) $tariff->name,
                        'price_per_hour' => (int) $tariff->price_per_hour,
                    ] : null,
                    'started_at' => $session->started_at?->toIso8601String(),
                    'price_total' => (int) $session->price_total,
                    'is_package' => (bool) $session->is_package,
                    'seconds_left' => (int) ($time['seconds_left'] ?? 0),
                    'from' => (string) ($time['from'] ?? 'balance'),
                    'rate_per_hour' => (int) ($time['rate_per_hour'] ?? 0),
                ];
            })
            ->values();
    }
}
