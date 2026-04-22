<?php

namespace App\Services;

use App\Models\Client;
use App\Models\ClientTransaction;
use App\Models\Shift;
use Carbon\Carbon;

class ClientTopupService
{
    public function __construct(
        private readonly PromotionEngine $promotions,
        private readonly ClientTierService $tiers,
    ) {}

    public function applyTopup(
        Client $client,
        int $tenantId,
        int $operatorId,
        Shift $shift,
        int $amount,
        string $paymentMethod,
        int $manualBonus = 0,
        ?string $comment = null,
        ?Carbon $now = null,
    ): array {
        $now ??= now();

        $promoResult = $this->promotions->calculateTopupBonus($tenantId, $paymentMethod, $amount, $now);
        $promotion = $promoResult['promotion'];
        $promoBonus = (int)($promoResult['bonus'] ?? 0);
        $finalBonus = $promoBonus + max(0, $manualBonus);

        $client->increment('balance', $amount);

        if ($finalBonus > 0) {
            $client->increment('bonus', $finalBonus);
        }

        $client->increment('lifetime_topup', $amount);

        ClientTransaction::create([
            'tenant_id' => $tenantId,
            'client_id' => $client->id,
            'operator_id' => $operatorId,
            'shift_id' => $shift->id,
            'type' => 'topup',
            'amount' => $amount,
            'bonus_amount' => $finalBonus,
            'payment_method' => $paymentMethod,
            'comment' => $comment,
            'promotion_id' => $promotion?->id,
        ]);

        $client = $client->fresh();

        $this->tiers->recalculateAndApplyUpgradeBonus(
            $client,
            $tenantId,
            $operatorId,
            $shift->id,
            $now,
        );

        $client->refresh();

        return [
            'client' => $client,
            'promotion' => $promotion,
            'bonus_amount' => $finalBonus,
        ];
    }
}
