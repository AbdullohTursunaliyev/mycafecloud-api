<?php

namespace App\Console\Commands;

use App\Models\Booking;
use App\Models\Pc;
use App\Models\PcCommand;
use App\Models\Session;
use Illuminate\Console\Command;

class BookingExpireTick extends Command
{
    protected $signature = 'bookings:expire';
    protected $description = 'Expire past bookings and sync reserved PC statuses';

    public function handle(): int
    {
        $now = now();
        $affectedPcIds = [];

        $expired = Booking::query()
            ->where('status', 'active')
            ->where('end_at', '<', $now)
            ->get();

        foreach ($expired as $b) {
            $b->update(['status' => 'expired']);
            $affectedPcIds[] = (int)$b->pc_id;
        }

        // Hozirgi vaqtda faol booking mavjud bo'lgan PKlar ham sync bo'lsin.
        $activeNowPcIds = Booking::query()
            ->where('status', 'active')
            ->where('start_at', '<=', $now)
            ->where('end_at', '>=', $now)
            ->pluck('pc_id')
            ->all();

        $affectedPcIds = array_values(array_unique(array_merge($affectedPcIds, array_map('intval', $activeNowPcIds))));

        foreach ($affectedPcIds as $pcId) {
            $this->syncPc($pcId, $now);
        }

        $this->info('bookings synced: ' . count($affectedPcIds));
        return self::SUCCESS;
    }

    private function syncPc(int $pcId, $now): void
    {
        $pc = Pc::query()->find($pcId);
        if (!$pc) {
            return;
        }

        $tenantId = (int)$pc->tenant_id;

        $hasActiveSession = Session::query()
            ->where('tenant_id', $tenantId)
            ->where('pc_id', $pcId)
            ->where('status', 'active')
            ->exists();

        if ($hasActiveSession) {
            if ($pc->status !== 'busy') {
                $pc->update(['status' => 'busy']);
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
            if ($pc->status !== 'reserved') {
                $pc->update(['status' => 'reserved']);
            }
            $this->createLockCommandIfNeeded($tenantId, $pcId);
            return;
        }

        if ($pc->status === 'reserved') {
            $fallback = ($pc->last_seen_at && $pc->last_seen_at->gte($now->copy()->subMinutes(3)))
                ? 'online'
                : 'offline';
            $pc->update(['status' => $fallback]);
        }
    }

    private function createLockCommandIfNeeded(int $tenantId, int $pcId): void
    {
        $pendingExists = PcCommand::query()
            ->where('tenant_id', $tenantId)
            ->where('pc_id', $pcId)
            ->where('type', 'LOCK')
            ->whereIn('status', ['pending', 'sent'])
            ->where('created_at', '>=', now()->subMinutes(2))
            ->exists();

        if ($pendingExists) {
            return;
        }

        PcCommand::create([
            'tenant_id' => $tenantId,
            'pc_id' => $pcId,
            'type' => 'LOCK',
            'payload' => ['reason' => 'booking_reserved'],
            'status' => 'pending',
        ]);
    }
}

