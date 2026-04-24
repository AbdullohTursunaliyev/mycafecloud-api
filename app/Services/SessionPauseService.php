<?php

namespace App\Services;

use App\Models\Session;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SessionPauseService
{
    public function __construct(
        private readonly SessionBillingService $billing,
    ) {
    }

    public function pause(Session $session, ?Carbon $now = null): Session
    {
        $now = ($now ?: now())->copy();

        return DB::transaction(function () use ($session, $now) {
            $locked = Session::query()->whereKey($session->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== 'active') {
                throw ValidationException::withMessages(['id' => 'Сессия не активна']);
            }

            if ($locked->paused_at) {
                return $locked;
            }

            $this->billing->billSingleSession($locked, $now);
            $locked->refresh();

            if ($locked->status !== 'active') {
                return $locked;
            }

            $locked->paused_at = $now;
            $locked->save();

            return $locked;
        });
    }
}
