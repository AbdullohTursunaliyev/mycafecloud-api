<?php

namespace App\Services;

use App\Models\Client;

class ClientWalletService
{
    public function total(Client $client): int
    {
        $balance = max(0, (int) ($client->balance ?? 0));
        $bonus = max(0, (int) ($client->bonus ?? 0));

        return $balance + $bonus;
    }

    public function charge(Client $client, int $amount): array
    {
        $need = max(0, (int) $amount);
        $balance = max(0, (int) ($client->balance ?? 0));
        $bonus = max(0, (int) ($client->bonus ?? 0));

        if ($need <= 0) {
            return [
                'charged' => 0,
                'balance_before' => $balance,
                'bonus_before' => $bonus,
                'balance_after' => $balance,
                'bonus_after' => $bonus,
            ];
        }

        $available = $balance + $bonus;
        if ($available <= 0) {
            return [
                'charged' => 0,
                'balance_before' => $balance,
                'bonus_before' => $bonus,
                'balance_after' => $balance,
                'bonus_after' => $bonus,
            ];
        }

        $charge = min($need, $available);
        $fromBalance = min($balance, $charge);
        $fromBonus = min($bonus, $charge - $fromBalance);

        $client->balance = $balance - $fromBalance;
        $client->bonus = $bonus - $fromBonus;
        $client->save();

        return [
            'charged' => $charge,
            'balance_before' => $balance,
            'bonus_before' => $bonus,
            'balance_after' => (int) $client->balance,
            'bonus_after' => (int) $client->bonus,
        ];
    }
}
