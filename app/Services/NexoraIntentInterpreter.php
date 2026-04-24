<?php

namespace App\Services;

class NexoraIntentInterpreter
{
    public function interpret(string $message): array
    {
        $normalized = $this->normalize($message);

        if ($normalized === '') {
            return [
                'action' => 'unsupported',
                'confidence' => 'low',
            ];
        }

        if ($this->looksLikeShutdownIdle($normalized)) {
            return [
                'action' => 'shutdown_idle_pcs',
                'confidence' => 'high',
            ];
        }

        if ($this->looksLikeLockIdle($normalized)) {
            return [
                'action' => 'lock_idle_pcs',
                'confidence' => 'high',
            ];
        }

        if ($this->looksLikeRebootPcs($normalized)) {
            return [
                'action' => 'reboot_named_pcs',
                'confidence' => 'high',
            ];
        }

        if ($this->looksLikeZoneMessage($normalized)) {
            return [
                'action' => 'message_zone_pcs',
                'confidence' => 'medium',
            ];
        }

        if ($this->looksLikeTodayRevenue($normalized)) {
            return [
                'action' => 'today_revenue',
                'confidence' => 'medium',
            ];
        }

        if ($this->looksLikeOfflineList($normalized)) {
            return [
                'action' => 'offline_pc_list',
                'confidence' => 'medium',
            ];
        }

        if ($this->looksLikeIdleList($normalized)) {
            return [
                'action' => 'idle_pc_list',
                'confidence' => 'medium',
            ];
        }

        if ($this->looksLikeHallSnapshot($normalized)) {
            return [
                'action' => 'hall_snapshot',
                'confidence' => 'medium',
            ];
        }

        return [
            'action' => 'unsupported',
            'confidence' => 'low',
        ];
    }

    private function looksLikeShutdownIdle(string $normalized): bool
    {
        return $this->containsAny($normalized, [
            'o‘chir',
            "o'chir",
            'ochir',
            'выключ',
            'отключ',
            'shutdown',
            'power off',
            'turn off',
        ]) && $this->containsAny($normalized, [
            "odam yo'q",
            'odam yoq',
            "bo'sh",
            'bosh',
            'empty',
            'idle',
            'free',
            'без людей',
            'свобод',
            'пуст',
        ]);
    }

    private function looksLikeLockIdle(string $normalized): bool
    {
        return $this->containsAny($normalized, [
            'lock',
            'qulfl',
            'blokla',
            'заблок',
            'lock qil',
        ]) && $this->containsAny($normalized, [
            "bo'sh",
            'bosh',
            'idle',
            'free',
            'empty',
            'свобод',
            'пуст',
        ]);
    }

    private function looksLikeRebootPcs(string $normalized): bool
    {
        return $this->containsAny($normalized, [
            'reboot',
            'restart',
            'qayta ishga tush',
            'перезагруз',
            'restart qil',
        ]) && $this->containsAny($normalized, [
            'pc',
            'kompyuter',
            'компьютер',
            'computer',
        ]);
    }

    private function looksLikeZoneMessage(string $normalized): bool
    {
        return $this->containsAny($normalized, [
            'xabar',
            'message',
            'сообщен',
            'send message',
            'yubor',
            'отправ',
            'ayt',
        ]) && $this->containsAny($normalized, [
            'zona',
            'zone',
            'зона',
        ]);
    }

    private function looksLikeTodayRevenue(string $normalized): bool
    {
        return $this->containsAny($normalized, [
            'bugun',
            'today',
            'сегодня',
        ]) && $this->containsAny($normalized, [
            'tushum',
            'revenue',
            'sales',
            'выручк',
            'daromad',
        ]);
    }

    private function looksLikeOfflineList(string $normalized): bool
    {
        return $this->containsAny($normalized, [
            'offline',
            'offlayn',
            'офлайн',
        ]) && $this->containsAny($normalized, [
            'pc',
            'kompyuter',
            'компьютер',
            'computer',
            'qaysi',
            'which',
            'какие',
        ]);
    }

    private function looksLikeIdleList(string $normalized): bool
    {
        return $this->containsAny($normalized, [
            'qaysi',
            "bo'sh",
            'bosh',
            'empty',
            'idle',
            'free',
            'свобод',
            'пуст',
        ]) && $this->containsAny($normalized, [
            'pc',
            'kompyuter',
            'компьютер',
            'computer',
        ]);
    }

    private function looksLikeHallSnapshot(string $normalized): bool
    {
        return $this->containsAny($normalized, [
            'holat',
            'status',
            'nechta',
            'qancha',
            'zal',
            'snapshot',
            'overview',
            'summary',
            'how many',
            'сколько',
            'сводк',
            'зал',
        ]);
    }

    private function containsAny(string $haystack, array $needles): bool
    {
        foreach ($needles as $needle) {
            if ($needle !== '' && str_contains($haystack, $needle)) {
                return true;
            }
        }

        return false;
    }

    private function normalize(string $message): string
    {
        $message = mb_strtolower(trim($message));
        $message = str_replace(["\n", "\r", "\t"], ' ', $message);
        $message = preg_replace('/\s+/u', ' ', $message) ?: '';

        return trim($message);
    }
}
