<?php

namespace App\Services;

use App\Models\Client;
use App\Models\ClientPackage;
use App\Models\Pc;
use App\Models\Session;
use Carbon\Carbon;

class SessionProjectionService
{
    public function __construct(
        private readonly PricingRuleResolver $pricing,
        private readonly SessionMeteringService $metering,
        private readonly ClientWalletService $wallets,
    ) {
    }

    public function describe(Session $session, ?Client $client, Pc $pc, ?Carbon $now = null): array
    {
        $now = ($now ?: now())->copy();
        $rule = $this->pricing->resolveCurrent($session, $now);

        $secondsLeft = 0;
        $from = 'balance';

        if ((bool) $session->is_package && $session->client_package_id) {
            $package = $session->relationLoaded('clientPackage') ? $session->clientPackage : null;
            if (!$package) {
                $package = ClientPackage::query()->with('package')->find($session->client_package_id);
            }

            if ($package && (int) $package->remaining_min > 0) {
                $secondsLeft = $this->metering->countdownSeconds($session, (int) $package->remaining_min, $now);
                $from = 'package';
            }
        } elseif ($client && (int) ($rule['rate_per_hour'] ?? 0) > 0) {
            $walletTotal = $this->wallets->total($client);
            $walletMinutes = $this->pricing->projectWalletBillableMinutes($session, $walletTotal, $now);
            $secondsLeft = $this->metering->countdownSeconds($session, $walletMinutes, $now);
            $from = $this->resolveWalletSource($client);
        }

        return [
            'seconds_left' => $secondsLeft,
            'from' => $from,
            'zone_name' => $rule['zone_name'],
            'rate_per_hour' => (int) ($rule['rate_per_hour'] ?? 0),
            'next_charge_at' => $secondsLeft > 0 && $session->paused_at === null
                ? $this->metering->nextAnchor($session, 1, $now)->toIso8601String()
                : null,
            'paused' => $session->paused_at !== null,
            'pricing_rule' => [
                'type' => $rule['rule_type'],
                'id' => $rule['rule_id'],
                'window_id' => $rule['window_id'],
                'window_name' => $rule['window_name'],
            ],
        ];
    }

    private function resolveWalletSource(Client $client): string
    {
        $balance = max(0, (int) ($client->balance ?? 0));
        $bonus = max(0, (int) ($client->bonus ?? 0));

        return $balance > 0 ? 'balance' : ($bonus > 0 ? 'bonus' : 'balance');
    }
}
