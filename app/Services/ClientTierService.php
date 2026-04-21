<?php

namespace App\Services;

use App\Models\Client;
use App\Models\ClientTier;
use App\Models\ClientTransaction;
use Illuminate\Support\Carbon;

class ClientTierService
{
    /**
     * Topupdan keyin chaqiriladi.
     * - client->lifetime_topup yangilangan bo'lishi kerak.
     * - upgrade bo'lsa: client bonusiga tier bonus qo'shadi va transaction yozadi.
     */
    public static function recalcAndApplyUpgradeBonus(
        Client $client,
        int $tenantId,
        int $operatorId,
        ?int $shiftId,
        Carbon $now
    ): array {
        $oldTierId = $client->tier_id;

        $tiers = ClientTier::query()
            ->where('tenant_id', $tenantId)
            ->orderBy('min_total', 'asc')
            ->get();

        if ($tiers->isEmpty()) {
            return ['changed' => false, 'bonus' => 0, 'tier' => null];
        }

        // find best tier by lifetime_topup
        $newTier = $tiers->where('min_total', '<=', (int)$client->lifetime_topup)->last();

        if (!$newTier) {
            $newTier = $tiers->first();
        }

        // no change
        if ((int)$oldTierId === (int)$newTier->id) {
            return ['changed' => false, 'bonus' => 0, 'tier' => $newTier];
        }

        // upgrade (or downgrade?) — biz faqat upgrade qilamiz
        // downgrade bo'lmasin desangiz:
        if ($oldTierId) {
            $oldTier = $tiers->firstWhere('id', (int)$oldTierId);
            if ($oldTier && (int)$newTier->min_total < (int)$oldTier->min_total) {
                // downgrade qilishni xohlamaymiz
                return ['changed' => false, 'bonus' => 0, 'tier' => $oldTier];
            }
        }

        // apply tier change
        $client->tier_id = $newTier->id;
        $client->tier_changed_at = $now;
        $client->save();

        $bonus = (int)$newTier->bonus_on_upgrade;

        if ($bonus > 0) {
            // bonus cash emas => shift kassaga ta'sir qilmaydi
            $client->increment('bonus', $bonus);

            ClientTransaction::create([
                'tenant_id' => $tenantId,
                'client_id' => $client->id,
                'operator_id' => $operatorId,
                'shift_id' => $shiftId, // audit uchun qo'yamiz (topup bo'lgan smena)
                'type' => 'tier_upgrade_bonus', // YANGI TYPE
                'amount' => 0,
                'bonus_amount' => $bonus,
                'payment_method' => 'system',
                'comment' => 'Бонус за повышение уровня: '.$newTier->name,
                'meta' => [
                    'tier_id' => $newTier->id,
                    'tier_name' => $newTier->name,
                ],
            ]);
        }

        return ['changed' => true, 'bonus' => $bonus, 'tier' => $newTier];
    }
}
