<?php

namespace App\Services;

use App\Models\Session;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SessionResumeService
{
    public function resume(Session $session, ?Carbon $now = null): Session
    {
        $now = ($now ?: now())->copy();

        return DB::transaction(function () use ($session, $now) {
            $locked = Session::query()->whereKey($session->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== 'active') {
                throw ValidationException::withMessages(['id' => 'Сессия не активна']);
            }

            if (!$locked->paused_at) {
                return $locked;
            }

            $pausedAt = $locked->paused_at->copy();
            $pauseSeconds = max(0, (int) $pausedAt->diffInSeconds($now, false));
            if ($pauseSeconds > 0) {
                $locked->started_at = $locked->started_at?->copy()->addSeconds($pauseSeconds);
                if ($locked->last_billed_at) {
                    $locked->last_billed_at = $locked->last_billed_at->copy()->addSeconds($pauseSeconds);
                }
            }

            $locked->paused_at = null;
            $locked->save();

            return $locked;
        });
    }
}
