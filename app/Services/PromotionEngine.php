<?php

namespace App\Services;

use App\Models\Promotion;
use Carbon\Carbon;

class PromotionEngine
{
    /**
     * Topup uchun aktiv promo topadi va bonus qaytaradi.
     *
     * @return array{promotion: Promotion|null, bonus: int}
     */
    public function calculateTopupBonus(
        int $tenantId,
        string $paymentMethod,
        int $amount,
        ?Carbon $now = null
    ): array {
        $now = $now ?: now();

        // faqat amount>0 bo'lsa ma'noli
        if ($amount <= 0) {
            return ['promotion' => null, 'bonus' => 0];
        }

        $promo = $this->findActiveTopupPromotion($tenantId, $paymentMethod, $now);
        if ($promo && $promo->type === 'double_topup') {
            return [
                'promotion' => $promo,
                'bonus' => $amount,
            ];
        }

        return ['promotion' => null, 'bonus' => 0];
    }

    public function findActiveTopupPromotion(
        int $tenantId,
        string $paymentMethod,
        ?Carbon $now = null
    ): ?Promotion {
        $now = $now ?: now();

        $promos = Promotion::query()
            ->where('tenant_id', $tenantId)
            ->where('is_active', true)
            ->where(function ($query) use ($paymentMethod) {
                $query->where('applies_payment_method', 'any')
                    ->orWhere('applies_payment_method', $paymentMethod);
            })
            ->where(function ($query) use ($now) {
                $query->whereNull('starts_at')->orWhere('starts_at', '<=', $now);
            })
            ->where(function ($query) use ($now) {
                $query->whereNull('ends_at')->orWhere('ends_at', '>=', $now);
            })
            ->orderByDesc('priority')
            ->orderByDesc('id')
            ->get();

        foreach ($promos as $promo) {
            if ($this->isPromoActiveNow($promo, $now)) {
                return $promo;
            }
        }

        return null;
    }

    public static function calcTopupBonus(
        int $tenantId,
        string $paymentMethod,
        int $amount,
        ?Carbon $now = null
    ): array {
        return app(self::class)->calculateTopupBonus($tenantId, $paymentMethod, $amount, $now);
    }

    private function isPromoActiveNow(Promotion $promo, Carbon $now): bool
    {
        // days_of_week check (optional)
        $days = $promo->days_of_week;
        if (is_array($days) && count($days) > 0) {
            // Carbon: 0=Sunday ... 5=Friday
            if (!in_array($now->dayOfWeek, $days, true)) {
                return false;
            }
        }

        // time window check (optional)
        if ($promo->time_from || $promo->time_to) {
            $cur = $now->format('H:i:s');
            $from = $promo->time_from ? (string)$promo->time_from : null;
            $to   = $promo->time_to ? (string)$promo->time_to : null;

            if ($from && $to) {
                if ($from <= $to) {
                    if (!($cur >= $from && $cur <= $to)) {
                        return false;
                    }
                } elseif (!($cur >= $from || $cur <= $to)) {
                    return false;
                }
            } elseif ($from && !$to) {
                if (!($cur >= $from)) {
                    return false;
                }
            } elseif (!$from && $to) {
                if (!($cur <= $to)) {
                    return false;
                }
            }
        }

        return true;
    }
}
