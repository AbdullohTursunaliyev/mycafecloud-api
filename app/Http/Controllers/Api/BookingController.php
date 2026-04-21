<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\Client;
use App\Models\Pc;
use App\Models\PcCommand;
use App\Models\Session;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class BookingController extends Controller
{
    private const TECHNICAL_BOOKING_YEARS = 20;

    public function index(Request $request)
    {
        $tenantId = (int)$request->user()->tenant_id;

        $q = Booking::query()
            ->where('tenant_id', $tenantId)
            ->with([
                'pc:id,tenant_id,code,status',
                'client:id,tenant_id,account_id,login,phone',
                'creator:id,tenant_id,login,name',
            ])
            ->orderByDesc('start_at');

        if ($request->filled('status')) {
            $q->where('status', $request->string('status'));
        }
        if ($request->filled('pc_id')) {
            $q->where('pc_id', (int)$request->input('pc_id'));
        }
        if ($request->filled('client_id')) {
            $q->where('client_id', (int)$request->input('client_id'));
        }
        if ($request->filled('from')) {
            $q->where('start_at', '>=', Carbon::parse($request->input('from'))->startOfDay());
        }
        if ($request->filled('to')) {
            $q->where('start_at', '<=', Carbon::parse($request->input('to'))->endOfDay());
        }

        $rows = $q->paginate((int)$request->input('per_page', 20));

        $rows->getCollection()->transform(function (Booking $b) {
            return [
                'id' => (int)$b->id,
                'pc_id' => (int)$b->pc_id,
                'client_id' => (int)$b->client_id,
                'start_at' => optional($b->start_at)?->toIso8601String(),
                'end_at' => optional($b->end_at)?->toIso8601String(),
                'status' => (string)$b->status,
                'note' => $b->note,
                'created_at' => optional($b->created_at)?->toIso8601String(),
                'pc' => $b->pc ? [
                    'id' => (int)$b->pc->id,
                    'code' => $b->pc->code,
                    'status' => $b->pc->status,
                ] : null,
                'client' => $b->client ? [
                    'id' => (int)$b->client->id,
                    'account_id' => $b->client->account_id,
                    'login' => $b->client->login,
                    'phone' => $b->client->phone,
                ] : null,
                'creator' => $b->creator ? [
                    'id' => (int)$b->creator->id,
                    'login' => $b->creator->login,
                    'name' => $b->creator->name,
                ] : null,
            ];
        });

        return response()->json(['data' => $rows]);
    }

    public function store(Request $request)
    {
        $tenantId = (int)$request->user()->tenant_id;
        $operatorId = (int)$request->user()->id;

        $data = $request->validate([
            'pc_id' => ['required', 'integer'],
            'client_id' => ['required', 'integer'],
            'start_at' => ['required', 'date'],
            'end_at' => ['nullable', 'date'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        $start = Carbon::parse($data['start_at']);
        // Business qoida: bookingda ketish vaqti yo'q.
        // DB compatibility uchun texnik end_at uzoq muddatga qo'yiladi.
        $end = $start->copy()->addYears(self::TECHNICAL_BOOKING_YEARS);
        $now = now();

        if ($end->lte($start)) {
            throw ValidationException::withMessages([
                'end_at' => 'Booking end time startdan keyin bo\'lishi kerak',
            ]);
        }
        if ($start->lt($now->copy()->subMinutes(1))) {
            throw ValidationException::withMessages([
                'start_at' => 'Start time hozirgi vaqtdan oldin bo\'lmasin',
            ]);
        }

        $booking = DB::transaction(function () use ($tenantId, $operatorId, $data, $start, $end, $now) {
            $pcId = (int)$data['pc_id'];
            $clientId = (int)$data['client_id'];

            // Race holatlarda bir vaqtning o'zida 2 ta booking yozilib ketmasin.
            DB::select('SELECT pg_advisory_xact_lock(?)', [$tenantId * 1000000 + $pcId]);
            DB::select('SELECT pg_advisory_xact_lock(?)', [$tenantId * 1000000 + 500000 + $clientId]);

            $pc = Pc::query()->where('tenant_id', $tenantId)->lockForUpdate()->findOrFail($pcId);
            $client = Client::query()->where('tenant_id', $tenantId)->lockForUpdate()->findOrFail($clientId);

            if ($client->status !== 'active') {
                throw ValidationException::withMessages(['client_id' => 'Mijoz bloklangan']);
            }
            if ($client->expires_at && $client->expires_at->isPast()) {
                throw ValidationException::withMessages(['client_id' => 'Mijoz muddati tugagan']);
            }

            // Business qoida: PC bo'shamaguncha (active session tugamaguncha)
            // unga umuman yangi booking qo'yilmaydi.
            $busyNow = Session::query()
                ->where('tenant_id', $tenantId)
                ->where('pc_id', $pcId)
                ->where('status', 'active')
                ->exists();
            if ($busyNow) {
                throw ValidationException::withMessages([
                    'pc_id' => 'Bu PK hozir band (active session), bo\'shamaguncha booking qo\'yib bo\'lmaydi',
                ]);
            }

            // Bitta PC uchun bitta active booking: boshqa booking qo'yilmaydi.
            $pcOverlap = Booking::query()
                ->where('tenant_id', $tenantId)
                ->where('pc_id', $pcId)
                ->where('status', 'active')
                ->lockForUpdate()
                ->exists();
            if ($pcOverlap) {
                throw ValidationException::withMessages([
                    'pc_id' => 'Bu PK allaqachon bron qilingan, avval oldingi bookingni yakunlang yoki bekor qiling',
                ]);
            }

            // Bitta mijoz uchun ham bitta active booking.
            $clientOverlap = Booking::query()
                ->where('tenant_id', $tenantId)
                ->where('client_id', $clientId)
                ->where('status', 'active')
                ->lockForUpdate()
                ->exists();
            if ($clientOverlap) {
                throw ValidationException::withMessages([
                    'client_id' => 'Mijozda allaqachon aktiv booking mavjud',
                ]);
            }

            $booking = Booking::create([
                'tenant_id' => $tenantId,
                'pc_id' => $pcId,
                'client_id' => $clientId,
                'created_by_operator_id' => $operatorId,
                'start_at' => $start,
                'end_at' => $end,
                'status' => 'active',
                'note' => $data['note'] ?? null,
            ]);

            if ($now->between($start, $end) && $pc->status !== 'busy') {
                $pc->update(['status' => 'reserved']);
                $this->createLockCommandIfNeeded($tenantId, $pcId, 'booking_reserved');
            }

            return $booking->load([
                'pc:id,tenant_id,code,status',
                'client:id,tenant_id,account_id,login,phone',
                'creator:id,tenant_id,login,name',
            ]);
        });

        return response()->json(['data' => $booking], 201);
    }

    public function cancel(Request $request, int $id)
    {
        $tenantId = (int)$request->user()->tenant_id;

        $booking = Booking::query()
            ->where('tenant_id', $tenantId)
            ->findOrFail($id);

        if ($booking->status !== 'active') {
            throw ValidationException::withMessages([
                'status' => 'Booking allaqachon faol emas',
            ]);
        }

        $booking->update(['status' => 'canceled']);
        $this->syncPcAfterBookingChange($tenantId, (int)$booking->pc_id);

        return response()->json(['ok' => true]);
    }

    private function syncPcAfterBookingChange(int $tenantId, int $pcId): void
    {
        $now = now();
        $pc = Pc::query()->where('tenant_id', $tenantId)->find($pcId);
        if (!$pc) {
            return;
        }

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
            $this->createLockCommandIfNeeded($tenantId, $pcId, 'booking_reserved');
            return;
        }

        if ($pc->status === 'reserved') {
            $fallback = ($pc->last_seen_at && $pc->last_seen_at->gte($now->copy()->subMinutes(3)))
                ? 'online'
                : 'offline';
            $pc->update(['status' => $fallback]);
        }
    }

    private function createLockCommandIfNeeded(int $tenantId, int $pcId, string $reason): void
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
            'payload' => ['reason' => $reason],
            'status' => 'pending',
        ]);
    }
}
