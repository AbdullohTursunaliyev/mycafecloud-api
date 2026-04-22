<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\Mobile\MobilePayloadResource;
use App\Services\MobileFriendService;
use Illuminate\Http\Request;

class MobileFriendController extends Controller
{
    public function __construct(
        private readonly MobileFriendService $friends,
    ) {
    }

    public function index(Request $request)
    {
        $mobileUserId = (int) $request->attributes->get('mobile_user_id');
        if ($mobileUserId <= 0) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        return new MobilePayloadResource($this->friends->index($mobileUserId));
    }

    public function search(Request $request)
    {
        $mobileUserId = (int) $request->attributes->get('mobile_user_id');
        if ($mobileUserId <= 0) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $data = $request->validate([
            'q' => ['required', 'string', 'min:1', 'max:64'],
        ]);

        return new MobilePayloadResource(
            $this->friends->search($mobileUserId, (string) $data['q'])
        );
    }

    public function sendRequest(Request $request)
    {
        $mobileUserId = (int) $request->attributes->get('mobile_user_id');
        if ($mobileUserId <= 0) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $data = $request->validate([
            'friend_mobile_user_id' => ['required', 'integer', 'min:1'],
        ]);

        return $this->friendResponse(
            $this->friends->sendRequest($mobileUserId, (int) $data['friend_mobile_user_id'])
        );
    }

    public function respondRequest(Request $request, int $id)
    {
        $mobileUserId = (int) $request->attributes->get('mobile_user_id');
        if ($mobileUserId <= 0) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $data = $request->validate([
            'action' => ['required', 'string', 'in:accept,reject'],
        ]);

        return $this->friendResponse(
            $this->friends->respondRequest($mobileUserId, $id, (string) $data['action'])
        );
    }

    public function remove(Request $request, int $friendMobileUserId)
    {
        $mobileUserId = (int) $request->attributes->get('mobile_user_id');
        if ($mobileUserId <= 0) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        return $this->friendResponse(
            $this->friends->remove($mobileUserId, $friendMobileUserId)
        );
    }

    public function invites(Request $request)
    {
        $mobileUser = $this->friends->resolveMobileUserFromClientContext(
            (int) $request->attributes->get('tenant_id'),
            (int) $request->attributes->get('client_id'),
        );

        if (!$mobileUser) {
            return new MobilePayloadResource([
                'incoming' => [],
                'outgoing' => [],
            ]);
        }

        return new MobilePayloadResource(
            $this->friends->invites((int) $request->attributes->get('tenant_id'), $mobileUser)
        );
    }

    public function invite(Request $request)
    {
        $tenantId = (int) $request->attributes->get('tenant_id');
        $mobileUser = $this->friends->resolveMobileUserFromClientContext(
            $tenantId,
            (int) $request->attributes->get('client_id'),
        );

        if (!$mobileUser) {
            return response()->json(['message' => 'Mobile account is not linked'], 422);
        }

        $data = $request->validate([
            'friend_mobile_user_id' => ['required', 'integer', 'min:1'],
            'note' => ['nullable', 'string', 'max:180'],
        ]);

        return $this->friendResponse(
            $this->friends->invite(
                $tenantId,
                $mobileUser,
                (int) $data['friend_mobile_user_id'],
                $data['note'] ?? null,
            )
        );
    }

    public function respondInvite(Request $request, int $inviteId)
    {
        $tenantId = (int) $request->attributes->get('tenant_id');
        $mobileUser = $this->friends->resolveMobileUserFromClientContext(
            $tenantId,
            (int) $request->attributes->get('client_id'),
        );

        if (!$mobileUser) {
            return response()->json(['message' => 'Mobile account is not linked'], 422);
        }

        $data = $request->validate([
            'action' => ['required', 'string', 'in:accept,reject'],
        ]);

        return $this->friendResponse(
            $this->friends->respondInvite($tenantId, $mobileUser, $inviteId, (string) $data['action'])
        );
    }

    private function friendResponse(array $payload)
    {
        if (isset($payload['status_code'])) {
            $statusCode = (int) $payload['status_code'];
            unset($payload['status_code']);

            return response()->json($payload, $statusCode);
        }

        return new MobilePayloadResource($payload);
    }
}
