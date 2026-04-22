<?php

namespace App\Console\Commands;

use App\Models\Booking;
use App\Services\BookingPcStateService;
use Illuminate\Console\Command;

class BookingExpireTick extends Command
{
    protected $signature = 'bookings:expire';
    protected $description = 'Expire past bookings and sync reserved PC statuses';

    public function __construct(
        private readonly BookingPcStateService $pcState,
    ) {
        parent::__construct();
    }

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
            $tenantId = Booking::query()
                ->where('pc_id', $pcId)
                ->orderByDesc('id')
                ->value('tenant_id');

            if (!$tenantId) {
                continue;
            }

            $this->pcState->syncPcAfterBookingChange((int) $tenantId, $pcId, $now);
        }

        $this->info('bookings synced: ' . count($affectedPcIds));
        return self::SUCCESS;
    }
}
