<?php

namespace App\Services;

class ClientRankService
{
    /**
     * Global (barcha klub uchun bir xil) rank config.
     * total_topup (UZS) bo‘yicha.
     */
    public static function tiers(): array
    {
        return [
            [
                'key' => 'novice',
                'name' => 'Новичок',
                'min' => 0,
                'bonus_percent' => 0,
                'color' => '#94A3B8', // slate
                'icon' => 'spark',
            ],
            [
                'key' => 'gamer',
                'name' => 'Геймер',
                'min' => 800_000,
                'bonus_percent' => 2,
                'color' => '#22D3EE', // cyan
                'icon' => 'gamepad',
            ],
            [
                'key' => 'knight',
                'name' => 'Рыцарь',
                'min' => 2_000_000,
                'bonus_percent' => 3,
                'color' => '#8B5CF6', // violet
                'icon' => 'shield',
            ],
            [
                'key' => 'investor',
                'name' => 'Инвестор',
                'min' => 5_000_000,
                'bonus_percent' => 5,
                'color' => '#F59E0B', // amber
                'icon' => 'crown',
            ],
            [
                'key' => 'legend',
                'name' => 'Легенда',
                'min' => 10_000_000,
                'bonus_percent' => 7,
                'color' => '#EC4899', // pink
                'icon' => 'bolt',
            ],
        ];
    }

    /**
     * totalTopup bo‘yicha rank + progress qaytaradi.
     */
    public static function byTotalTopup(int $totalTopup): array
    {
        $tiers = self::tiers();

        // current
        $current = $tiers[0];
        foreach ($tiers as $t) {
            if ($totalTopup >= (int)$t['min']) $current = $t;
        }

        // next
        $next = null;
        foreach ($tiers as $t) {
            if ((int)$t['min'] > (int)$current['min']) {
                $next = $t;
                break;
            }
        }

        $currentMin = (int)$current['min'];
        $nextMin = $next ? (int)$next['min'] : null;

        // progress [0..1]
        $progress = 1.0;
        $remainingToNext = null;
        if ($nextMin !== null) {
            $span = max(1, $nextMin - $currentMin);
            $progress = ($totalTopup - $currentMin) / $span;
            if ($progress < 0) $progress = 0;
            if ($progress > 1) $progress = 1;
            $remainingToNext = max(0, $nextMin - $totalTopup);
        }

        return [
            'current' => [
                'key' => (string)$current['key'],
                'name' => (string)$current['name'],
                'min_total_topup' => $currentMin,
                'bonus_percent' => (int)$current['bonus_percent'],
                'color' => (string)$current['color'],
                'icon' => (string)$current['icon'],
            ],
            'next' => $next ? [
                'key' => (string)$next['key'],
                'name' => (string)$next['name'],
                'min_total_topup' => $nextMin,
                'bonus_percent' => (int)$next['bonus_percent'],
                'color' => (string)$next['color'],
                'icon' => (string)$next['icon'],
            ] : null,
            'stats' => [
                'total_topup' => (int)$totalTopup,
                'progress' => (float)$progress,
                'remaining_to_next' => $remainingToNext, // null bo‘lishi mumkin (max rank)
            ],
        ];
    }
}
