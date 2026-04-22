<?php

namespace App\Actions\Booking;

use App\Enums\PcStatus;
use App\Models\Booking;
use App\Models\Client;
use App\Models\Pc;
use App\Models\Session;
use App\Services\BookingPcStateService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CreateBookingAction
{
    private const TECHNICAL_BOOKING_YEARS = 20;

    public function __construct(
        private readonly BookingPcStateService $pcState,
    ) {
    }

    public function execute(int $tenantId, int $operatorId, array $attributes): Booking
    {
        $start = Carbon::parse((string) $attributes['start_at']);
        $end = $start->copy()->addYears(self::TECHNICAL_BOOKING_YEARS);
        $now = now();

        if ($end->lte($start)) {
            throw ValidationException::withMessages([
                'end_at' => 'Booking end time must be after start time',
            ]);
        }

        if ($start->lt($now->copy()->subMinutes(1))) {
            throw ValidationException::withMessages([
                'start_at' => 'Start time cannot be in the past',
            ]);
        }

        $booking = DB::transaction(function () use ($tenantId, $operatorId, $attributes, $start, $end, $now) {
            $pcId = (int) $attributes['pc_id'];
            $clientId = (int) $attributes['client_id'];

            DB::select('SELECT pg_advisory_xact_lock(?)', [$tenantId * 1000000 + $pcId]);
            DB::select('SELECT pg_advisory_xact_lock(?)', [$tenantId * 1000000 + 500000 + $clientId]);

            $pc = Pc::query()
                ->where('tenant_id', $tenantId)
                ->lockForUpdate()
                ->findOrFail($pcId);

            $client = Client::query()
                ->where('tenant_id', $tenantId)
                ->lockForUpdate()
                ->findOrFail($clientId);

            if ($client->status !== 'active') {
                throw ValidationException::withMessages([
                    'client_id' => 'Client is blocked',
                ]);
            }

            if ($client->expires_at && $client->expires_at->isPast()) {
                throw ValidationException::withMessages([
                    'client_id' => 'Client account expired',
                ]);
            }

            $busyNow = Session::query()
                ->where('tenant_id', $tenantId)
                ->where('pc_id', $pcId)
                ->where('status', 'active')
                ->exists();

            if ($busyNow) {
                throw ValidationException::withMessages([
                    'pc_id' => 'PC is busy with an active session',
                ]);
            }

            $pcOverlap = Booking::query()
                ->where('tenant_id', $tenantId)
                ->where('pc_id', $pcId)
                ->where('status', 'active')
                ->lockForUpdate()
                ->exists();

            if ($pcOverlap) {
                throw ValidationException::withMessages([
                    'pc_id' => 'This PC already has an active booking',
                ]);
            }

            $clientOverlap = Booking::query()
                ->where('tenant_id', $tenantId)
                ->where('client_id', $clientId)
                ->where('status', 'active')
                ->lockForUpdate()
                ->exists();

            if ($clientOverlap) {
                throw ValidationException::withMessages([
                    'client_id' => 'Client already has an active booking',
                ]);
            }

            $booking = Booking::query()->create([
                'tenant_id' => $tenantId,
                'pc_id' => $pcId,
                'client_id' => $clientId,
                'created_by_operator_id' => $operatorId,
                'start_at' => $start,
                'end_at' => $end,
                'status' => 'active',
                'note' => $attributes['note'] ?? null,
            ]);

            if ($now->between($start, $end) && $pc->status !== PcStatus::Busy->value) {
                $pc->update(['status' => PcStatus::Reserved->value]);
                $this->pcState->createLockCommandIfNeeded($tenantId, $pcId, 'booking_reserved');
            }

            return $booking;
        });

        return $booking->load([
            'pc:id,tenant_id,code,status',
            'client:id,tenant_id,account_id,login,phone',
            'creator:id,tenant_id,login,name',
        ]);
    }
}
