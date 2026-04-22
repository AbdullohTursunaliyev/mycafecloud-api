<?php

namespace App\Services;

use App\Enums\PcCommandType;
use App\Enums\PcStatus;
use App\Models\Booking;
use App\Models\Pc;
use App\Models\PcCommand;
use App\Models\Session;
use Carbon\Carbon;

class BookingPcStateService
{
    public function syncPcAfterBookingChange(int $tenantId, int $pcId, ?Carbon $now = null): void
    {
        $now ??= now();

        $pc = Pc::query()
            ->where('tenant_id', $tenantId)
            ->find($pcId);

        if (!$pc) {
            return;
        }

        $hasActiveSession = Session::query()
            ->where('tenant_id', $tenantId)
            ->where('pc_id', $pcId)
            ->where('status', 'active')
            ->exists();

        if ($hasActiveSession) {
            if ($pc->status !== PcStatus::Busy->value) {
                $pc->update(['status' => PcStatus::Busy->value]);
            }

            return;
        }

        $hasBookingNow = Booking::query()
            ->where('tenant_id', $tenantId)
            ->where('pc_id', $pcId)
            ->where('status', 'active')
            ->where('start_at', '<=', $now)
            ->where('end_at', '>=', $now)
            ->exists();

        if ($hasBookingNow) {
            if ($pc->status !== PcStatus::Reserved->value) {
                $pc->update(['status' => PcStatus::Reserved->value]);
            }

            $this->createLockCommandIfNeeded($tenantId, $pcId, 'booking_reserved');

            return;
        }

        if ($pc->status === PcStatus::Reserved->value) {
            $fallback = ($pc->last_seen_at && $pc->last_seen_at->gte($now->copy()->subMinutes((int) config('domain.pc.online_window_minutes', 3))))
                ? PcStatus::Online->value
                : PcStatus::Offline->value;

            $pc->update(['status' => $fallback]);
        }
    }

    public function createLockCommandIfNeeded(int $tenantId, int $pcId, string $reason): void
    {
        $pendingExists = PcCommand::query()
            ->where('tenant_id', $tenantId)
            ->where('pc_id', $pcId)
            ->where('type', PcCommandType::Lock->value)
            ->whereIn('status', ['pending', 'sent'])
            ->where('created_at', '>=', now()->subMinutes((int) config('domain.pc.lock_command_recent_window_minutes', 2)))
            ->exists();

        if ($pendingExists) {
            return;
        }

        PcCommand::query()->create([
            'tenant_id' => $tenantId,
            'pc_id' => $pcId,
            'type' => PcCommandType::Lock->value,
            'payload' => ['reason' => $reason],
            'status' => 'pending',
        ]);
    }
}
