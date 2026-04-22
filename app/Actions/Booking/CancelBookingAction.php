<?php

namespace App\Actions\Booking;

use App\Models\Booking;
use App\Services\BookingPcStateService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CancelBookingAction
{
    public function __construct(
        private readonly BookingPcStateService $pcState,
    ) {
    }

    public function execute(int $tenantId, int $bookingId): void
    {
        DB::transaction(function () use ($tenantId, $bookingId) {
            $booking = Booking::query()
                ->where('tenant_id', $tenantId)
                ->lockForUpdate()
                ->findOrFail($bookingId);

            if ($booking->status !== 'active') {
                throw ValidationException::withMessages([
                    'status' => 'Booking is no longer active',
                ]);
            }

            $booking->update(['status' => 'canceled']);
            $this->pcState->syncPcAfterBookingChange($tenantId, (int) $booking->pc_id);
        });
    }
}
