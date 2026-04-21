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
    public static function calcTopupBonus(
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

        // Promolar: tenant + active + method mos
        $q = Promotion::query()
            ->where('tenant_id', $tenantId)
            ->where('is_active', true);

        // payment method: cash/card/any
        $q->where(function ($qq) use ($paymentMethod) {
            $qq->where('applies_payment_method', 'any')
                ->orWhere('applies_payment_method', $paymentMethod);
        });

        // campaign bounds (optional)
        $q->where(function ($qq) use ($now) {
            $qq->whereNull('starts_at')->orWhere('starts_at', '<=', $now);
        });
        $q->where(function ($qq) use ($now) {
            $qq->whereNull('ends_at')->orWhere('ends_at', '>=', $now);
        });

        // priority: yuqori ustun
        $promos = $q->orderByDesc('priority')->orderByDesc('id')->get();

        foreach ($promos as $promo) {
            if (!self::isPromoActiveNow($promo, $now)) {
                continue;
            }

            // hozircha faqat 2x
            if ($promo->type === 'double_topup') {
                return [
                    'promotion' => $promo,
                    'bonus' => $amount, // 2x: bonus = amount
                ];
            }
        }

        return ['promotion' => null, 'bonus' => 0];
    }

    private static function isPromoActiveNow(Promotion $promo, Carbon $now): bool
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

            // oddiy (from <= cur <= to)
            if ($from && $to) {
                if (!($cur >= $from && $cur <= $to)) return false;
            } elseif ($from && !$to) {
                if (!($cur >= $from)) return false;
            } elseif (!$from && $to) {
                if (!($cur <= $to)) return false;
            }
        }

        return true;
    }
}
