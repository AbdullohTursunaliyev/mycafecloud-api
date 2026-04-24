<?php

namespace App\Services;

use App\Models\Session;
use Carbon\Carbon;

class SessionMeteringService
{
    public function pricePerMinute(int $pricePerHour): int
    {
        return max(0, (int) ceil($pricePerHour / 60));
    }

    public function affordableMinutes(int $walletTotal, int $pricePerHour): int
    {
        $pricePerMinute = $this->pricePerMinute($pricePerHour);
        if ($pricePerMinute <= 0) {
            return 0;
        }

        return (int) floor(max(0, $walletTotal) / $pricePerMinute);
    }

    public function completedMinutes(Session $session, ?Carbon $now = null, ?int $capMinutes = null): int
    {
        $elapsedSeconds = $this->elapsedSeconds($session, $now);
        $minutes = (int) floor($elapsedSeconds / 60);

        if ($capMinutes !== null) {
            $minutes = min($minutes, max(0, $capMinutes));
        }

        return max(0, $minutes);
    }

    public function countdownSeconds(Session $session, int $remainingMinutes, ?Carbon $now = null): int
    {
        $rawSeconds = max(0, $remainingMinutes) * 60;
        if ($rawSeconds <= 0) {
            return 0;
        }

        return max(0, $rawSeconds - $this->elapsedSeconds($session, $now));
    }

    public function nextAnchor(Session $session, int $chargedMinutes, ?Carbon $now = null): Carbon
    {
        $anchor = $this->billingAnchor($session, $now) ?: ($now ?: now())->copy();

        return $anchor->copy()->addMinutes(max(0, $chargedMinutes));
    }

    public function effectiveNow(Session $session, ?Carbon $now = null): Carbon
    {
        $now = ($now ?: now())->copy();
        if (!$session->paused_at) {
            return $now;
        }

        $pausedAt = $session->paused_at->copy();

        return $pausedAt->lessThan($now) ? $pausedAt : $now;
    }

    public function billingAnchor(Session $session, ?Carbon $now = null): ?Carbon
    {
        $anchor = $session->last_billed_at ?: $session->started_at ?: $now;

        return $anchor?->copy();
    }

    private function elapsedSeconds(Session $session, ?Carbon $now = null): int
    {
        $anchor = $this->billingAnchor($session, $now);
        if (!$anchor) {
            return 0;
        }

        return max(0, (int) $anchor->diffInSeconds($this->effectiveNow($session, $now), false));
    }
}
