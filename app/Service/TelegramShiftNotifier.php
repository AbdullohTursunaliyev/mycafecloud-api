<?php

namespace App\Service;

class TelegramShiftNotifier
{
    public static function shiftOpened(int $tenantId, array $payload): void
    {
        app(\App\Services\TelegramShiftNotifier::class)->shiftOpened($tenantId, $payload);
    }

    public static function shiftClosed(int $tenantId, array $payload): void
    {
        app(\App\Services\TelegramShiftNotifier::class)->shiftClosed($tenantId, $payload);
    }
}
