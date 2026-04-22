<?php

namespace App\Enums;

enum PcCommandType: string
{
    case Lock = 'LOCK';
    case Unlock = 'UNLOCK';
    case Reboot = 'REBOOT';
    case Shutdown = 'SHUTDOWN';
    case Message = 'MESSAGE';
    case InstallGame = 'INSTALL_GAME';
    case UpdateGame = 'UPDATE_GAME';
    case RollbackGame = 'ROLLBACK_GAME';
    case UpdateShell = 'UPDATE_SHELL';
    case RunScript = 'RUN_SCRIPT';
    case ApplyCloudProfile = 'APPLY_CLOUD_PROFILE';
    case BackupCloudProfile = 'BACKUP_CLOUD_PROFILE';

    public static function values(): array
    {
        return array_map(
            static fn(self $case) => $case->value,
            self::cases(),
        );
    }

    public static function rolloutValues(): array
    {
        return self::values();
    }

    public static function agentDeliveryValues(): array
    {
        return [
            self::Lock->value,
            self::Unlock->value,
            self::Reboot->value,
            self::Shutdown->value,
            self::Message->value,
            self::InstallGame->value,
            self::UpdateGame->value,
            self::RollbackGame->value,
            self::UpdateShell->value,
            self::RunScript->value,
        ];
    }
}
