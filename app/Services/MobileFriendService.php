<?php

namespace App\Services;

use App\Models\Client;
use App\Models\Event;
use App\Models\MobileFriendship;
use App\Models\MobileUser;
use App\Models\Tenant;

class MobileFriendService
{
    public function index(int $mobileUserId): array
    {
        $rows = MobileFriendship::query()
            ->where(function ($query) use ($mobileUserId) {
                $query->where('mobile_user_id', $mobileUserId)
                    ->orWhere('friend_mobile_user_id', $mobileUserId);
            })
            ->whereIn('status', ['pending', 'accepted'])
            ->orderByDesc('updated_at')
            ->get([
                'id',
                'mobile_user_id',
                'friend_mobile_user_id',
                'requested_by_mobile_user_id',
                'status',
                'accepted_at',
                'created_at',
                'updated_at',
            ]);

        $otherIds = [];
        foreach ($rows as $row) {
            $other = (int) $row->mobile_user_id === $mobileUserId
                ? (int) $row->friend_mobile_user_id
                : (int) $row->mobile_user_id;

            if ($other > 0) {
                $otherIds[$other] = true;
            }
        }

        if ($otherIds === []) {
            return [
                'friends' => [],
                'incoming' => [],
                'outgoing' => [],
            ];
        }

        $users = MobileUser::query()
            ->whereIn('id', array_keys($otherIds))
            ->get($this->mobileUserColumns())
            ->keyBy('id');

        $friends = [];
        $incoming = [];
        $outgoing = [];

        foreach ($rows as $row) {
            $other = (int) $row->mobile_user_id === $mobileUserId
                ? (int) $row->friend_mobile_user_id
                : (int) $row->mobile_user_id;

            if ($other <= 0 || !isset($users[$other])) {
                continue;
            }

            $user = $users[$other];
            $item = [
                'friendship_id' => (int) $row->id,
                'mobile_user_id' => (int) $user->id,
                'login' => (string) $user->login,
                'first_name' => $this->nullableText($user->first_name),
                'last_name' => $this->nullableText($user->last_name),
                'avatar_url' => $this->nullableText($user->avatar_url),
                'status' => (string) $row->status,
                'requested_by_mobile_user_id' => (int) $row->requested_by_mobile_user_id,
                'accepted_at' => $row->accepted_at?->toISOString(),
                'created_at' => $row->created_at?->toISOString(),
                'updated_at' => $row->updated_at?->toISOString(),
            ];

            if ((string) $row->status === 'accepted') {
                $friends[] = $item;
            } elseif ((int) $row->requested_by_mobile_user_id === $mobileUserId) {
                $outgoing[] = $item;
            } else {
                $incoming[] = $item;
            }
        }

        return [
            'friends' => array_values($friends),
            'incoming' => array_values($incoming),
            'outgoing' => array_values($outgoing),
        ];
    }

    public function search(int $mobileUserId, string $query): array
    {
        $query = trim($query);
        if ($query === '') {
            return ['items' => []];
        }

        $queryLower = mb_strtolower($query);
        $users = MobileUser::query()
            ->where('id', '!=', $mobileUserId)
            ->where(function ($builder) use ($queryLower) {
                $builder->whereRaw('LOWER(login) LIKE ?', ['%' . $queryLower . '%'])
                    ->orWhereRaw('LOWER(first_name) LIKE ?', ['%' . $queryLower . '%'])
                    ->orWhereRaw('LOWER(last_name) LIKE ?', ['%' . $queryLower . '%']);
            })
            ->orderBy('login')
            ->limit(20)
            ->get($this->mobileUserColumns());

        $relationMap = $this->relationStatusMap(
            $mobileUserId,
            $users->pluck('id')->map(fn($id) => (int) $id)->values()->all(),
        );

        return [
            'items' => $users->map(function (MobileUser $user) use ($relationMap) {
                return [
                    'mobile_user_id' => (int) $user->id,
                    'login' => (string) $user->login,
                    'first_name' => $this->nullableText($user->first_name),
                    'last_name' => $this->nullableText($user->last_name),
                    'avatar_url' => $this->nullableText($user->avatar_url),
                    'relation_status' => $relationMap[(int) $user->id] ?? 'none',
                ];
            })->values()->all(),
        ];
    }

    public function sendRequest(int $mobileUserId, int $friendMobileUserId): array
    {
        if ($friendMobileUserId === $mobileUserId) {
            return ['message' => 'You cannot add yourself', 'status_code' => 422];
        }

        $friend = MobileUser::query()->find($friendMobileUserId, ['id']);
        if (!$friend) {
            return ['message' => 'User not found', 'status_code' => 404];
        }

        [$left, $right] = $this->pair($mobileUserId, $friendMobileUserId);

        $friendship = MobileFriendship::query()
            ->where('mobile_user_id', $left)
            ->where('friend_mobile_user_id', $right)
            ->first();

        if ($friendship) {
            $status = (string) ($friendship->status ?? '');
            if ($status === 'accepted') {
                return ['ok' => true, 'status' => 'accepted'];
            }

            if ($status === 'pending') {
                if ((int) $friendship->requested_by_mobile_user_id !== $mobileUserId) {
                    $friendship->status = 'accepted';
                    $friendship->accepted_at = now();
                    $friendship->save();

                    return ['ok' => true, 'status' => 'accepted'];
                }

                return ['ok' => true, 'status' => 'pending'];
            }

            return ['message' => 'Friendship is blocked', 'status_code' => 422];
        }

        MobileFriendship::query()->create([
            'mobile_user_id' => $left,
            'friend_mobile_user_id' => $right,
            'requested_by_mobile_user_id' => $mobileUserId,
            'status' => 'pending',
            'accepted_at' => null,
        ]);

        return ['ok' => true, 'status' => 'pending'];
    }

    public function respondRequest(int $mobileUserId, int $friendshipId, string $action): array
    {
        $friendship = MobileFriendship::query()
            ->where('id', $friendshipId)
            ->where('status', 'pending')
            ->where(function ($query) use ($mobileUserId) {
                $query->where('mobile_user_id', $mobileUserId)
                    ->orWhere('friend_mobile_user_id', $mobileUserId);
            })
            ->first();

        if (!$friendship) {
            return ['message' => 'Request not found', 'status_code' => 404];
        }

        if ((int) $friendship->requested_by_mobile_user_id === $mobileUserId) {
            return ['message' => 'You cannot respond to your own request', 'status_code' => 422];
        }

        if ($action === 'reject') {
            $friendship->delete();
            return ['ok' => true, 'status' => 'rejected'];
        }

        $friendship->status = 'accepted';
        $friendship->accepted_at = now();
        $friendship->save();

        return ['ok' => true, 'status' => 'accepted'];
    }

    public function remove(int $mobileUserId, int $friendMobileUserId): array
    {
        if ($friendMobileUserId <= 0 || $friendMobileUserId === $mobileUserId) {
            return ['message' => 'Invalid friend id', 'status_code' => 422];
        }

        [$left, $right] = $this->pair($mobileUserId, $friendMobileUserId);

        MobileFriendship::query()
            ->where('mobile_user_id', $left)
            ->where('friend_mobile_user_id', $right)
            ->delete();

        return ['ok' => true];
    }

    public function invites(int $tenantId, MobileUser $mobileUser): array
    {
        $mobileUserId = (int) $mobileUser->id;

        $incomingEvents = Event::query()
            ->where('tenant_id', $tenantId)
            ->where('type', 'mobile_friend_invite')
            ->where('entity_type', 'mobile_user')
            ->where('entity_id', $mobileUserId)
            ->orderByDesc('id')
            ->limit(80)
            ->get(['id', 'payload', 'created_at']);

        $incomingResponses = Event::query()
            ->where('tenant_id', $tenantId)
            ->where('type', 'mobile_friend_invite_response')
            ->where('entity_type', 'mobile_user')
            ->where('entity_id', $mobileUserId)
            ->orderByDesc('id')
            ->limit(200)
            ->get(['payload']);

        $respondedIncoming = [];
        foreach ($incomingResponses as $row) {
            $payload = $this->eventPayload($row->payload);
            $inviteId = (int) ($payload['invite_id'] ?? 0);
            if ($inviteId > 0) {
                $respondedIncoming[$inviteId] = true;
            }
        }

        $incoming = [];
        foreach ($incomingEvents as $event) {
            $inviteId = (int) $event->id;
            if (isset($respondedIncoming[$inviteId])) {
                continue;
            }

            $payload = $this->eventPayload($event->payload);
            $incoming[] = [
                'invite_id' => $inviteId,
                'from_mobile_user_id' => (int) ($payload['from_mobile_user_id'] ?? 0),
                'from_login' => (string) ($payload['from_login'] ?? ''),
                'note' => (string) ($payload['note'] ?? ''),
                'created_at' => (string) $event->created_at,
            ];
        }

        $outgoingEvents = Event::query()
            ->where('tenant_id', $tenantId)
            ->where('type', 'mobile_friend_invite')
            ->where('source', 'mobile')
            ->orderByDesc('id')
            ->limit(200)
            ->get(['id', 'payload', 'created_at']);

        $outgoingResponses = Event::query()
            ->where('tenant_id', $tenantId)
            ->where('type', 'mobile_friend_invite_response')
            ->orderByDesc('id')
            ->limit(400)
            ->get(['payload']);

        $responseByInvite = [];
        foreach ($outgoingResponses as $row) {
            $payload = $this->eventPayload($row->payload);
            $inviteId = (int) ($payload['invite_id'] ?? 0);
            if ($inviteId > 0 && !isset($responseByInvite[$inviteId])) {
                $responseByInvite[$inviteId] = (string) ($payload['action'] ?? '');
            }
        }

        $outgoing = [];
        foreach ($outgoingEvents as $event) {
            $payload = $this->eventPayload($event->payload);
            if ((int) ($payload['from_mobile_user_id'] ?? 0) !== $mobileUserId) {
                continue;
            }

            $inviteId = (int) $event->id;
            $outgoing[] = [
                'invite_id' => $inviteId,
                'to_mobile_user_id' => (int) ($payload['to_mobile_user_id'] ?? 0),
                'to_login' => (string) ($payload['to_login'] ?? ''),
                'note' => (string) ($payload['note'] ?? ''),
                'status' => $responseByInvite[$inviteId] ?? 'pending',
                'created_at' => (string) $event->created_at,
            ];
        }

        return [
            'incoming' => array_values($incoming),
            'outgoing' => array_values($outgoing),
        ];
    }

    public function invite(int $tenantId, MobileUser $mobileUser, int $friendMobileUserId, ?string $note): array
    {
        $mobileUserId = (int) $mobileUser->id;
        if ($friendMobileUserId === $mobileUserId) {
            return ['message' => 'You cannot invite yourself', 'status_code' => 422];
        }

        $friendUser = MobileUser::query()->find($friendMobileUserId, ['id', 'login']);
        if (!$friendUser) {
            return ['message' => 'Friend not found', 'status_code' => 404];
        }

        [$left, $right] = $this->pair($mobileUserId, $friendMobileUserId);
        $accepted = MobileFriendship::query()
            ->where('mobile_user_id', $left)
            ->where('friend_mobile_user_id', $right)
            ->where('status', 'accepted')
            ->exists();

        if (!$accepted) {
            return ['message' => 'You can invite only accepted friends', 'status_code' => 422];
        }

        $friendClientExists = Client::query()
            ->where('tenant_id', $tenantId)
            ->where('login', (string) $friendUser->login)
            ->where('status', 'active')
            ->exists();

        if (!$friendClientExists) {
            return ['message' => 'Friend is not in this club', 'status_code' => 422];
        }

        $recentInvites = Event::query()
            ->where('tenant_id', $tenantId)
            ->where('type', 'mobile_friend_invite')
            ->where('entity_type', 'mobile_user')
            ->where('entity_id', $friendMobileUserId)
            ->where('created_at', '>=', now()->subHours(12))
            ->orderByDesc('id')
            ->get(['id', 'payload']);

        $recentResponses = Event::query()
            ->where('tenant_id', $tenantId)
            ->where('type', 'mobile_friend_invite_response')
            ->where('entity_type', 'mobile_user')
            ->where('entity_id', $friendMobileUserId)
            ->where('created_at', '>=', now()->subHours(12))
            ->get(['payload']);

        $responded = [];
        foreach ($recentResponses as $row) {
            $payload = $this->eventPayload($row->payload);
            $inviteId = (int) ($payload['invite_id'] ?? 0);
            if ($inviteId > 0) {
                $responded[$inviteId] = true;
            }
        }

        foreach ($recentInvites as $invite) {
            $payload = $this->eventPayload($invite->payload);
            if ((int) ($payload['from_mobile_user_id'] ?? 0) !== $mobileUserId) {
                continue;
            }
            if (!isset($responded[(int) $invite->id])) {
                return [
                    'ok' => true,
                    'invite_id' => (int) $invite->id,
                    'status' => 'pending',
                ];
            }
        }

        $tenantName = (string) (Tenant::query()->find($tenantId, ['name'])->name ?? ('Club #' . $tenantId));
        $normalizedNote = trim((string) $note);
        $normalizedNote = $normalizedNote === '' ? null : $normalizedNote;

        $event = Event::query()->create([
            'tenant_id' => $tenantId,
            'type' => 'mobile_friend_invite',
            'source' => 'mobile',
            'entity_type' => 'mobile_user',
            'entity_id' => $friendMobileUserId,
            'payload' => [
                'from_mobile_user_id' => $mobileUserId,
                'from_login' => (string) $mobileUser->login,
                'to_mobile_user_id' => $friendMobileUserId,
                'to_login' => (string) $friendUser->login,
                'tenant_id' => $tenantId,
                'tenant_name' => $tenantName,
                'note' => $normalizedNote,
            ],
        ]);

        return [
            'ok' => true,
            'invite_id' => (int) $event->id,
            'status' => 'pending',
        ];
    }

    public function respondInvite(int $tenantId, MobileUser $mobileUser, int $inviteId, string $action): array
    {
        $invite = Event::query()
            ->where('tenant_id', $tenantId)
            ->where('id', $inviteId)
            ->where('type', 'mobile_friend_invite')
            ->where('entity_type', 'mobile_user')
            ->where('entity_id', (int) $mobileUser->id)
            ->first(['id', 'payload']);

        if (!$invite) {
            return ['message' => 'Invite not found', 'status_code' => 404];
        }

        $already = Event::query()
            ->where('tenant_id', $tenantId)
            ->where('type', 'mobile_friend_invite_response')
            ->where('entity_type', 'mobile_user')
            ->where('entity_id', (int) $mobileUser->id)
            ->get(['payload'])
            ->contains(function ($row) use ($inviteId) {
                $payload = $this->eventPayload($row->payload);
                return (int) ($payload['invite_id'] ?? 0) === $inviteId;
            });

        if ($already) {
            return ['ok' => true, 'status' => 'already_responded'];
        }

        Event::query()->create([
            'tenant_id' => $tenantId,
            'type' => 'mobile_friend_invite_response',
            'source' => 'mobile',
            'entity_type' => 'mobile_user',
            'entity_id' => (int) $mobileUser->id,
            'payload' => [
                'invite_id' => $inviteId,
                'action' => $action,
            ],
        ]);

        return ['ok' => true, 'status' => $action];
    }

    public function resolveMobileUserFromClientContext(int $tenantId, int $clientId): ?MobileUser
    {
        if ($tenantId <= 0 || $clientId <= 0) {
            return null;
        }

        $client = Client::query()
            ->where('tenant_id', $tenantId)
            ->find($clientId, ['id', 'login']);

        if (!$client) {
            return null;
        }

        return MobileUser::query()
            ->where('login', (string) $client->login)
            ->first(['id', 'login']);
    }

    private function relationStatusMap(int $mobileUserId, array $otherIds): array
    {
        $statuses = [];
        if ($otherIds === []) {
            return $statuses;
        }

        $rows = MobileFriendship::query()
            ->where(function ($query) use ($mobileUserId, $otherIds) {
                $query->where(function ($inner) use ($mobileUserId, $otherIds) {
                    $inner->where('mobile_user_id', $mobileUserId)
                        ->whereIn('friend_mobile_user_id', $otherIds);
                })->orWhere(function ($inner) use ($mobileUserId, $otherIds) {
                    $inner->where('friend_mobile_user_id', $mobileUserId)
                        ->whereIn('mobile_user_id', $otherIds);
                });
            })
            ->get([
                'mobile_user_id',
                'friend_mobile_user_id',
                'requested_by_mobile_user_id',
                'status',
            ]);

        foreach ($rows as $row) {
            $other = (int) $row->mobile_user_id === $mobileUserId
                ? (int) $row->friend_mobile_user_id
                : (int) $row->mobile_user_id;

            if ($other <= 0) {
                continue;
            }

            if ((string) $row->status === 'accepted') {
                $statuses[$other] = 'accepted';
                continue;
            }

            if ((string) $row->status === 'pending') {
                $statuses[$other] = (int) $row->requested_by_mobile_user_id === $mobileUserId
                    ? 'pending_outgoing'
                    : 'pending_incoming';
                continue;
            }

            $statuses[$other] = (string) ($row->status ?: 'none');
        }

        return $statuses;
    }

    private function pair(int $left, int $right): array
    {
        return $left < $right ? [$left, $right] : [$right, $left];
    }

    private function eventPayload(mixed $payload): array
    {
        if (is_array($payload)) {
            return $payload;
        }
        if (is_string($payload)) {
            $decoded = json_decode($payload, true);
            return is_array($decoded) ? $decoded : [];
        }

        return [];
    }

    private function mobileUserColumns(): array
    {
        return ['id', 'login', 'first_name', 'last_name', 'avatar_url'];
    }

    private function nullableText(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim((string) $value);
        return $trimmed === '' ? null : $trimmed;
    }
}
