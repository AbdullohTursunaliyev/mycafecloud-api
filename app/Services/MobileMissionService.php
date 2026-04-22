<?php

namespace App\Services;

use App\Models\Client;
use App\Models\ClientTransaction;
use App\Models\Event;
use App\Models\PcBooking;
use App\Models\Session;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class MobileMissionService
{
    public function build(int $tenantId, int $clientId): array
    {
        $dayStart = now()->startOfDay();
        $counters = $this->missionCounters($tenantId, $clientId, $dayStart);
        $claimedCodes = [];

        $claimedRows = Event::query()
            ->where('tenant_id', $tenantId)
            ->where('entity_type', 'client')
            ->where('entity_id', $clientId)
            ->where('type', 'mobile_mission_claim')
            ->where('created_at', '>=', $dayStart)
            ->get(['payload']);

        foreach ($claimedRows as $row) {
            $payload = $this->eventPayload($row->payload);
            $code = trim(strtolower((string) ($payload['code'] ?? '')));
            if ($code !== '') {
                $claimedCodes[$code] = true;
            }
        }

        $items = [];
        foreach ($this->missionDefinitions() as $definition) {
            $code = (string) $definition['code'];
            $target = (int) $definition['target'];
            $metric = (string) $definition['metric'];
            $progress = (int) ($counters[$metric] ?? 0);
            $complete = $progress >= $target;
            $claimed = isset($claimedCodes[$code]);

            $items[] = [
                'code' => $code,
                'title_key' => (string) $definition['title_key'],
                'unit' => (string) $definition['unit'],
                'target' => $target,
                'progress' => min($progress, $target),
                'raw_progress' => $progress,
                'progress_percent' => $target > 0 ? min(100, (int) floor(($progress * 100) / $target)) : 0,
                'reward_bonus' => (int) $definition['reward_bonus'],
                'complete' => $complete,
                'claimed' => $claimed,
                'can_claim' => $complete && !$claimed,
            ];
        }

        $allDone = collect($items)->every(static fn($item) => ($item['claimed'] ?? false) === true);

        return [
            'day_start' => $dayStart->toIso8601String(),
            'items' => $items,
            'all_claimed_today' => $allDone,
        ];
    }

    public function claim(int $tenantId, int $clientId, string $code): array
    {
        $code = trim(strtolower($code));

        $missions = $this->build($tenantId, $clientId);
        $mission = collect($missions['items'] ?? [])->firstWhere('code', $code);
        if (!$mission) {
            throw ValidationException::withMessages(['mission' => 'Mission not found']);
        }

        if (!($mission['complete'] ?? false)) {
            throw ValidationException::withMessages(['mission' => 'Mission is not complete yet']);
        }

        if (($mission['claimed'] ?? false) || !($mission['can_claim'] ?? false)) {
            throw ValidationException::withMessages(['mission' => 'Mission is already claimed']);
        }

        $rewardBonus = (int) ($mission['reward_bonus'] ?? 0);
        if ($rewardBonus <= 0) {
            throw ValidationException::withMessages(['mission' => 'Mission has no reward']);
        }

        $dayStart = now()->startOfDay();
        DB::transaction(function () use ($tenantId, $clientId, $code, $rewardBonus, $dayStart) {
            $client = Client::query()
                ->where('tenant_id', $tenantId)
                ->where('id', $clientId)
                ->lockForUpdate()
                ->firstOrFail();

            $claimedEvents = Event::query()
                ->where('tenant_id', $tenantId)
                ->where('entity_type', 'client')
                ->where('entity_id', $clientId)
                ->where('type', 'mobile_mission_claim')
                ->where('created_at', '>=', $dayStart)
                ->get(['payload']);

            foreach ($claimedEvents as $event) {
                $payload = $this->eventPayload($event->payload);
                if (($payload['code'] ?? null) === $code) {
                    throw ValidationException::withMessages([
                        'mission' => 'Mission already claimed',
                    ]);
                }
            }

            $client->bonus = (int) $client->bonus + $rewardBonus;
            $client->save();

            Event::query()->create([
                'tenant_id' => $tenantId,
                'type' => 'mobile_mission_claim',
                'source' => 'mobile',
                'entity_type' => 'client',
                'entity_id' => $clientId,
                'payload' => [
                    'code' => $code,
                    'reward_bonus' => $rewardBonus,
                ],
            ]);
        });

        $client = Client::query()->where('tenant_id', $tenantId)->findOrFail($clientId);

        return [
            'ok' => true,
            'code' => $code,
            'reward_bonus' => $rewardBonus,
            'client_bonus' => (int) $client->bonus,
        ];
    }

    private function missionDefinitions(): array
    {
        return [
            ['code' => 'topup_100k', 'title_key' => 'mission_topup_100k', 'metric' => 'topup_today', 'target' => 100000, 'unit' => 'uzs', 'reward_bonus' => 5000],
            ['code' => 'play_120m', 'title_key' => 'mission_play_120m', 'metric' => 'play_minutes_today', 'target' => 120, 'unit' => 'min', 'reward_bonus' => 7000],
            ['code' => 'book_1', 'title_key' => 'mission_booking_1', 'metric' => 'bookings_today', 'target' => 1, 'unit' => 'count', 'reward_bonus' => 3000],
        ];
    }

    private function missionCounters(int $tenantId, int $clientId, Carbon $dayStart): array
    {
        $topupToday = (int) ClientTransaction::query()
            ->where('tenant_id', $tenantId)
            ->where('client_id', $clientId)
            ->where('type', 'topup')
            ->where('created_at', '>=', $dayStart)
            ->sum('amount');

        $bookingsToday = (int) PcBooking::query()
            ->where('tenant_id', $tenantId)
            ->where('client_id', $clientId)
            ->where('created_at', '>=', $dayStart)
            ->count();

        $sessionRows = Session::query()
            ->where('tenant_id', $tenantId)
            ->where('client_id', $clientId)
            ->where(function ($query) use ($dayStart) {
                $query->where('started_at', '>=', $dayStart)
                    ->orWhere('ended_at', '>=', $dayStart)
                    ->orWhere(function ($inner) {
                        $inner->where('status', 'active')->whereNull('ended_at');
                    });
            })
            ->get(['started_at', 'ended_at', 'status']);

        $playMinutes = 0;
        foreach ($sessionRows as $session) {
            $startedAt = $session->started_at ? Carbon::parse((string) $session->started_at) : null;
            if (!$startedAt) {
                continue;
            }

            $start = $startedAt->greaterThan($dayStart) ? $startedAt : $dayStart;
            $end = $session->ended_at ? Carbon::parse((string) $session->ended_at) : now();
            if ($end->greaterThan($start)) {
                $playMinutes += (int) $start->diffInMinutes($end);
            }
        }

        return [
            'topup_today' => max(0, $topupToday),
            'play_minutes_today' => max(0, $playMinutes),
            'bookings_today' => max(0, $bookingsToday),
        ];
    }

    private function eventPayload($value): array
    {
        if (is_array($value)) {
            return $value;
        }

        if (is_string($value)) {
            $decoded = json_decode($value, true);

            return is_array($decoded) ? $decoded : [];
        }

        return [];
    }
}
