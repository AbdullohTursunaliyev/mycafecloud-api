<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\Event;
use App\Models\MobileUser;
use App\Models\Tenant;
use Illuminate\Http\Request;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class MobileFriendController extends Controller
{
    // GET /api/mobile/friends (mobile.auth)
    public function index(Request $request)
    {
        if (!Schema::hasTable('mobile_friendships')) {
            return response()->json([
                'friends' => [],
                'incoming' => [],
                'outgoing' => [],
            ]);
        }

        $me = (int)$request->attributes->get('mobile_user_id');
        if ($me <= 0) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $rows = DB::table('mobile_friendships')
            ->where(function ($q) use ($me) {
                $q->where('mobile_user_id', $me)
                    ->orWhere('friend_mobile_user_id', $me);
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
            $a = (int)($row->mobile_user_id ?? 0);
            $b = (int)($row->friend_mobile_user_id ?? 0);
            $other = $a === $me ? $b : $a;
            if ($other > 0) {
                $otherIds[$other] = true;
            }
        }

        if (empty($otherIds)) {
            return response()->json([
                'friends' => [],
                'incoming' => [],
                'outgoing' => [],
            ]);
        }

        $userColumns = $this->mobileUserColumns();

        $users = MobileUser::query()
            ->when(!empty($otherIds), function ($q) use ($otherIds) {
                $q->whereIn('id', array_keys($otherIds));
            })
            ->get($userColumns)
            ->keyBy('id');

        $friends = [];
        $incoming = [];
        $outgoing = [];

        foreach ($rows as $row) {
            $friendshipId = (int)$row->id;
            $a = (int)($row->mobile_user_id ?? 0);
            $b = (int)($row->friend_mobile_user_id ?? 0);
            $other = $a === $me ? $b : $a;
            if ($other <= 0 || !isset($users[$other])) {
                continue;
            }

            $u = $users[$other];
            $item = [
                'friendship_id' => $friendshipId,
                'mobile_user_id' => (int)$u->id,
                'login' => (string)$u->login,
                'first_name' => $this->nullableText($u->first_name),
                'last_name' => $this->nullableText($u->last_name),
                'avatar_url' => $this->nullableText($u->avatar_url),
                'status' => (string)$row->status,
                'requested_by_mobile_user_id' => (int)$row->requested_by_mobile_user_id,
                'accepted_at' => $row->accepted_at ? (string)$row->accepted_at : null,
                'created_at' => $row->created_at ? (string)$row->created_at : null,
                'updated_at' => $row->updated_at ? (string)$row->updated_at : null,
            ];

            if ((string)$row->status === 'accepted') {
                $friends[] = $item;
                continue;
            }

            if ((int)$row->requested_by_mobile_user_id === $me) {
                $outgoing[] = $item;
            } else {
                $incoming[] = $item;
            }
        }

        return response()->json([
            'friends' => array_values($friends),
            'incoming' => array_values($incoming),
            'outgoing' => array_values($outgoing),
        ]);
    }

    // GET /api/mobile/friends/search?q=...
    public function search(Request $request)
    {
        $me = (int)$request->attributes->get('mobile_user_id');
        if ($me <= 0) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $data = $request->validate([
            'q' => ['required', 'string', 'min:1', 'max:64'],
        ]);
        $q = trim((string)$data['q']);
        if ($q === '') {
            return response()->json(['items' => []]);
        }
        $qLower = mb_strtolower($q);

        $userColumns = $this->mobileUserColumns();

        $users = MobileUser::query()
            ->where('id', '!=', $me)
            ->where(function ($qBuilder) use ($qLower) {
                $qBuilder->whereRaw('LOWER(login) LIKE ?', ['%' . $qLower . '%']);
                if (Schema::hasColumn('mobile_users', 'first_name')) {
                    $qBuilder->orWhereRaw('LOWER(first_name) LIKE ?', ['%' . $qLower . '%']);
                }
                if (Schema::hasColumn('mobile_users', 'last_name')) {
                    $qBuilder->orWhereRaw('LOWER(last_name) LIKE ?', ['%' . $qLower . '%']);
                }
            })
            ->orderBy('login')
            ->limit(20)
            ->get($userColumns);

        $ids = $users->pluck('id')->map(fn($id) => (int)$id)->values()->all();
        $relationMap = $this->relationStatusMap($me, $ids);

        $items = $users->map(function (MobileUser $u) use ($relationMap) {
            $status = $relationMap[(int)$u->id] ?? 'none';
            return [
                'mobile_user_id' => (int)$u->id,
                'login' => (string)$u->login,
                'first_name' => $this->nullableText($u->first_name),
                'last_name' => $this->nullableText($u->last_name),
                'avatar_url' => $this->nullableText($u->avatar_url),
                'relation_status' => $status,
            ];
        })->values()->all();

        return response()->json(['items' => $items]);
    }

    // POST /api/mobile/friends/requests { friend_mobile_user_id }
    public function sendRequest(Request $request)
    {
        if (!$this->ensureFriendshipsTable()) {
            return response()->json(['message' => 'Friends are not ready yet'], 422);
        }

        $me = (int)$request->attributes->get('mobile_user_id');
        if ($me <= 0) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $data = $request->validate([
            'friend_mobile_user_id' => ['required', 'integer', 'min:1'],
        ]);

        $friend = (int)$data['friend_mobile_user_id'];
        if ($friend === $me) {
            return response()->json(['message' => 'You cannot add yourself'], 422);
        }

        $friendUser = MobileUser::query()->find($friend, ['id']);
        if (!$friendUser) {
            return response()->json(['message' => 'User not found'], 404);
        }

        [$left, $right] = $this->pair($me, $friend);

        $row = DB::table('mobile_friendships')
            ->where('mobile_user_id', $left)
            ->where('friend_mobile_user_id', $right)
            ->first();

        if ($row) {
            $status = (string)($row->status ?? '');
            if ($status === 'accepted') {
                return response()->json(['ok' => true, 'status' => 'accepted']);
            }
            if ($status === 'pending') {
                $requestedBy = (int)($row->requested_by_mobile_user_id ?? 0);
                if ($requestedBy !== $me) {
                    DB::table('mobile_friendships')
                        ->where('id', (int)$row->id)
                        ->update([
                            'status' => 'accepted',
                            'accepted_at' => now(),
                            'updated_at' => now(),
                        ]);
                    return response()->json(['ok' => true, 'status' => 'accepted']);
                }
                return response()->json(['ok' => true, 'status' => 'pending']);
            }
            return response()->json(['message' => 'Friendship is blocked'], 422);
        }

        DB::table('mobile_friendships')->insert([
            'mobile_user_id' => $left,
            'friend_mobile_user_id' => $right,
            'requested_by_mobile_user_id' => $me,
            'status' => 'pending',
            'accepted_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return response()->json(['ok' => true, 'status' => 'pending']);
    }

    // POST /api/mobile/friends/requests/{id}/respond { action: accept|reject }
    public function respondRequest(Request $request, int $id)
    {
        if (!$this->ensureFriendshipsTable()) {
            return response()->json(['message' => 'Friends are not ready yet'], 422);
        }

        $me = (int)$request->attributes->get('mobile_user_id');
        if ($me <= 0) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $data = $request->validate([
            'action' => ['required', 'string', 'in:accept,reject'],
        ]);
        $action = (string)$data['action'];

        $row = DB::table('mobile_friendships')
            ->where('id', $id)
            ->where('status', 'pending')
            ->where(function ($q) use ($me) {
                $q->where('mobile_user_id', $me)
                    ->orWhere('friend_mobile_user_id', $me);
            })
            ->first();

        if (!$row) {
            return response()->json(['message' => 'Request not found'], 404);
        }

        if ((int)$row->requested_by_mobile_user_id === $me) {
            return response()->json(['message' => 'You cannot respond to your own request'], 422);
        }

        if ($action === 'reject') {
            DB::table('mobile_friendships')->where('id', $id)->delete();
            return response()->json(['ok' => true, 'status' => 'rejected']);
        }

        DB::table('mobile_friendships')
            ->where('id', $id)
            ->update([
                'status' => 'accepted',
                'accepted_at' => now(),
                'updated_at' => now(),
            ]);

        return response()->json(['ok' => true, 'status' => 'accepted']);
    }

    // DELETE /api/mobile/friends/{friendMobileUserId}
    public function remove(Request $request, int $friendMobileUserId)
    {
        if (!$this->ensureFriendshipsTable()) {
            return response()->json(['ok' => true]);
        }

        $me = (int)$request->attributes->get('mobile_user_id');
        if ($me <= 0) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        if ($friendMobileUserId <= 0 || $friendMobileUserId === $me) {
            return response()->json(['message' => 'Invalid friend id'], 422);
        }

        [$left, $right] = $this->pair($me, $friendMobileUserId);

        DB::table('mobile_friendships')
            ->where('mobile_user_id', $left)
            ->where('friend_mobile_user_id', $right)
            ->delete();

        return response()->json(['ok' => true]);
    }

    // GET /api/mobile/friends/invites (client.auth)
    public function invites(Request $request)
    {
        $tenantId = (int)$request->attributes->get('tenant_id');
        $meUser = $this->resolveMobileUserFromClientAuth($request);
        if (!$meUser) {
            return response()->json([
                'incoming' => [],
                'outgoing' => [],
            ]);
        }
        $me = (int)$meUser->id;

        $incomingEvents = Event::query()
            ->where('tenant_id', $tenantId)
            ->where('type', 'mobile_friend_invite')
            ->where('entity_type', 'mobile_user')
            ->where('entity_id', $me)
            ->orderByDesc('id')
            ->limit(80)
            ->get(['id', 'payload', 'created_at']);

        $incomingResponses = Event::query()
            ->where('tenant_id', $tenantId)
            ->where('type', 'mobile_friend_invite_response')
            ->where('entity_type', 'mobile_user')
            ->where('entity_id', $me)
            ->orderByDesc('id')
            ->limit(200)
            ->get(['payload']);

        $respondedIncoming = [];
        foreach ($incomingResponses as $row) {
            $payload = $this->eventPayload($row->payload);
            $inviteId = (int)($payload['invite_id'] ?? 0);
            if ($inviteId > 0) {
                $respondedIncoming[$inviteId] = true;
            }
        }

        $incoming = [];
        foreach ($incomingEvents as $e) {
            $inviteId = (int)$e->id;
            if (isset($respondedIncoming[$inviteId])) {
                continue;
            }
            $payload = $this->eventPayload($e->payload);
            $incoming[] = [
                'invite_id' => $inviteId,
                'from_mobile_user_id' => (int)($payload['from_mobile_user_id'] ?? 0),
                'from_login' => (string)($payload['from_login'] ?? ''),
                'note' => (string)($payload['note'] ?? ''),
                'created_at' => (string)$e->created_at,
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
        foreach ($outgoingResponses as $resp) {
            $payload = $this->eventPayload($resp->payload);
            $inviteId = (int)($payload['invite_id'] ?? 0);
            if ($inviteId > 0 && !isset($responseByInvite[$inviteId])) {
                $responseByInvite[$inviteId] = (string)($payload['action'] ?? '');
            }
        }

        $outgoing = [];
        foreach ($outgoingEvents as $e) {
            $payload = $this->eventPayload($e->payload);
            if ((int)($payload['from_mobile_user_id'] ?? 0) !== $me) {
                continue;
            }
            $inviteId = (int)$e->id;
            $outgoing[] = [
                'invite_id' => $inviteId,
                'to_mobile_user_id' => (int)($payload['to_mobile_user_id'] ?? 0),
                'to_login' => (string)($payload['to_login'] ?? ''),
                'note' => (string)($payload['note'] ?? ''),
                'status' => $responseByInvite[$inviteId] ?? 'pending',
                'created_at' => (string)$e->created_at,
            ];
        }

        return response()->json([
            'incoming' => array_values($incoming),
            'outgoing' => array_values($outgoing),
        ]);
    }

    // POST /api/mobile/friends/invites { friend_mobile_user_id, note? } (client.auth)
    public function invite(Request $request)
    {
        if (!$this->ensureFriendshipsTable()) {
            return response()->json(['message' => 'Friends are not ready yet'], 422);
        }

        $tenantId = (int)$request->attributes->get('tenant_id');
        $meUser = $this->resolveMobileUserFromClientAuth($request);
        if (!$meUser) {
            return response()->json(['message' => 'Mobile account is not linked'], 422);
        }
        $me = (int)$meUser->id;

        $data = $request->validate([
            'friend_mobile_user_id' => ['required', 'integer', 'min:1'],
            'note' => ['nullable', 'string', 'max:180'],
        ]);

        $friendMobileUserId = (int)$data['friend_mobile_user_id'];
        if ($friendMobileUserId === $me) {
            return response()->json(['message' => 'You cannot invite yourself'], 422);
        }

        $friendUser = MobileUser::query()->find($friendMobileUserId, ['id', 'login']);
        if (!$friendUser) {
            return response()->json(['message' => 'Friend not found'], 404);
        }

        [$left, $right] = $this->pair($me, $friendMobileUserId);
        $accepted = DB::table('mobile_friendships')
            ->where('mobile_user_id', $left)
            ->where('friend_mobile_user_id', $right)
            ->where('status', 'accepted')
            ->exists();
        if (!$accepted) {
            return response()->json(['message' => 'You can invite only accepted friends'], 422);
        }

        $friendClientExists = Client::query()
            ->where('tenant_id', $tenantId)
            ->where('login', (string)$friendUser->login)
            ->where('status', 'active')
            ->exists();
        if (!$friendClientExists) {
            return response()->json(['message' => 'Friend is not in this club'], 422);
        }

        $tenantName = (string)(Tenant::query()->find($tenantId, ['name'])->name ?? ('Club #' . $tenantId));
        $note = trim((string)($data['note'] ?? ''));
        if ($note === '') {
            $note = null;
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
        foreach ($recentResponses as $r) {
            $payload = $this->eventPayload($r->payload);
            $inviteId = (int)($payload['invite_id'] ?? 0);
            if ($inviteId > 0) {
                $responded[$inviteId] = true;
            }
        }

        foreach ($recentInvites as $inv) {
            $payload = $this->eventPayload($inv->payload);
            if ((int)($payload['from_mobile_user_id'] ?? 0) !== $me) {
                continue;
            }
            if (!isset($responded[(int)$inv->id])) {
                return response()->json([
                    'ok' => true,
                    'invite_id' => (int)$inv->id,
                    'status' => 'pending',
                ]);
            }
        }

        $event = Event::query()->create([
            'tenant_id' => $tenantId,
            'type' => 'mobile_friend_invite',
            'source' => 'mobile',
            'entity_type' => 'mobile_user',
            'entity_id' => $friendMobileUserId,
            'payload' => [
                'from_mobile_user_id' => $me,
                'from_login' => (string)$meUser->login,
                'to_mobile_user_id' => $friendMobileUserId,
                'to_login' => (string)$friendUser->login,
                'tenant_id' => $tenantId,
                'tenant_name' => $tenantName,
                'note' => $note,
            ],
        ]);

        return response()->json([
            'ok' => true,
            'invite_id' => (int)$event->id,
            'status' => 'pending',
        ]);
    }

    // POST /api/mobile/friends/invites/{inviteId}/respond { action: accept|reject } (client.auth)
    public function respondInvite(Request $request, int $inviteId)
    {
        $tenantId = (int)$request->attributes->get('tenant_id');
        $meUser = $this->resolveMobileUserFromClientAuth($request);
        if (!$meUser) {
            return response()->json(['message' => 'Mobile account is not linked'], 422);
        }
        $me = (int)$meUser->id;

        $data = $request->validate([
            'action' => ['required', 'string', 'in:accept,reject'],
        ]);
        $action = (string)$data['action'];

        $invite = Event::query()
            ->where('tenant_id', $tenantId)
            ->where('id', $inviteId)
            ->where('type', 'mobile_friend_invite')
            ->where('entity_type', 'mobile_user')
            ->where('entity_id', $me)
            ->first(['id', 'payload']);

        if (!$invite) {
            return response()->json(['message' => 'Invite not found'], 404);
        }

        $already = Event::query()
            ->where('tenant_id', $tenantId)
            ->where('type', 'mobile_friend_invite_response')
            ->where('entity_type', 'mobile_user')
            ->where('entity_id', $me)
            ->get(['payload'])
            ->contains(function ($row) use ($inviteId) {
                $payload = $this->eventPayload($row->payload);
                return (int)($payload['invite_id'] ?? 0) === $inviteId;
            });

        if ($already) {
            return response()->json(['ok' => true, 'status' => 'already_responded']);
        }

        Event::query()->create([
            'tenant_id' => $tenantId,
            'type' => 'mobile_friend_invite_response',
            'source' => 'mobile',
            'entity_type' => 'mobile_user',
            'entity_id' => $me,
            'payload' => [
                'invite_id' => $inviteId,
                'action' => $action,
            ],
        ]);

        return response()->json(['ok' => true, 'status' => $action]);
    }

    private function pair(int $a, int $b): array
    {
        return $a < $b ? [$a, $b] : [$b, $a];
    }

    private function ensureFriendshipsTable(): bool
    {
        if (Schema::hasTable('mobile_friendships')) {
            return true;
        }

        try {
            Schema::create('mobile_friendships', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('mobile_user_id');
                $table->unsignedBigInteger('friend_mobile_user_id');
                $table->unsignedBigInteger('requested_by_mobile_user_id');
                $table->string('status', 24)->default('pending');
                $table->timestamp('accepted_at')->nullable();
                $table->timestamps();

                $table->unique(['mobile_user_id', 'friend_mobile_user_id'], 'mobile_friendships_pair_unique');
                $table->index(['status', 'updated_at']);
                $table->index(['requested_by_mobile_user_id']);
            });
        } catch (\Throwable $e) {
            return Schema::hasTable('mobile_friendships');
        }

        return true;
    }

    private function relationStatusMap(int $me, array $otherIds): array
    {
        $out = [];
        if (empty($otherIds) || !Schema::hasTable('mobile_friendships')) {
            return $out;
        }

        $rows = DB::table('mobile_friendships')
            ->where(function ($q) use ($me, $otherIds) {
                $q->where(function ($q1) use ($me, $otherIds) {
                    $q1->where('mobile_user_id', $me)
                        ->whereIn('friend_mobile_user_id', $otherIds);
                })->orWhere(function ($q2) use ($me, $otherIds) {
                    $q2->where('friend_mobile_user_id', $me)
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
            $a = (int)($row->mobile_user_id ?? 0);
            $b = (int)($row->friend_mobile_user_id ?? 0);
            $other = $a === $me ? $b : $a;
            if ($other <= 0) {
                continue;
            }

            $status = (string)($row->status ?? '');
            if ($status === 'accepted') {
                $out[$other] = 'accepted';
                continue;
            }
            if ($status === 'pending') {
                $requestedBy = (int)($row->requested_by_mobile_user_id ?? 0);
                $out[$other] = $requestedBy === $me ? 'pending_outgoing' : 'pending_incoming';
                continue;
            }
            $out[$other] = $status === '' ? 'none' : $status;
        }

        return $out;
    }

    private function resolveMobileUserFromClientAuth(Request $request): ?MobileUser
    {
        $tenantId = (int)$request->attributes->get('tenant_id');
        $clientId = (int)$request->attributes->get('client_id');
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
            ->where('login', (string)$client->login)
            ->first(['id', 'login']);
    }

    private function mobileUserColumns(): array
    {
        $out = ['id', 'login'];
        if (Schema::hasColumn('mobile_users', 'first_name')) {
            $out[] = 'first_name';
        }
        if (Schema::hasColumn('mobile_users', 'last_name')) {
            $out[] = 'last_name';
        }
        if (Schema::hasColumn('mobile_users', 'avatar_url')) {
            $out[] = 'avatar_url';
        }
        return $out;
    }

    private function nullableText(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $trim = trim((string)$value);
        return $trim === '' ? null : $trim;
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
}
