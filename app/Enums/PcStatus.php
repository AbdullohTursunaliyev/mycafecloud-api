<?php

namespace App\Enums;

enum PcStatus: string
{
    case Online = 'online';
    case Busy = 'busy';
    case Reserved = 'reserved';
    case Locked = 'locked';
    case Maintenance = 'maintenance';
    case Offline = 'offline';

    public static function values(): array
    {
        return array_map(
            static fn(self $case) => $case->value,
            self::cases(),
        );
    }

    public static function onlineValues(): array
    {
        return [
            self::Online->value,
            self::Busy->value,
            self::Reserved->value,
            self::Locked->value,
            self::Maintenance->value,
        ];
    }

    public static function agentReportedValues(): array
    {
        return [
            self::Online->value,
            self::Busy->value,
            self::Locked->value,
            self::Maintenance->value,
        ];
    }

    public static function agentWritableValues(): array
    {
        return [
            self::Online->value,
            self::Locked->value,
            self::Maintenance->value,
        ];
    }
}
