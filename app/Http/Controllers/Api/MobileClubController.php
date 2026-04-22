<?php

namespace App\Http\Controllers\Api;

use App\Actions\Mobile\BuildClubProfileAction;
use App\Actions\Mobile\ListClubReviewsAction;
use App\Actions\Mobile\SaveClubReviewAction;
use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\Tenant;
use App\Models\TenantJoinCode;
use App\Http\Resources\Mobile\MobileClubProfileResource;
use App\Http\Resources\Mobile\MobileClubReviewResource;
use App\Http\Resources\Mobile\MobileClubReviewsResource;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class MobileClubController extends Controller
{
    public function __construct(
        private readonly BuildClubProfileAction $buildClubProfile,
        private readonly ListClubReviewsAction $listClubReviews,
        private readonly SaveClubReviewAction $saveClubReview,
    ) {
    }

    public function joinByCode(Request $request)
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:32'],
            'password' => ['nullable', 'string', 'max:255'],
        ]);

        $login = (string)$request->attributes->get('mobile_login');

        $jc = TenantJoinCode::query()
            ->where('code', $data['code'])
            ->where('is_active', true)
            ->where(function ($q) {
                $q->whereNull('expires_at')->orWhere('expires_at', '>', now());
            })
            ->first();

        if (!$jc) {
            return response()->json(['message' => 'Join code is invalid'], 422);
        }

        $tenant = Tenant::query()->where('id', $jc->tenant_id)->where('status', 'active')->first();
        if (!$tenant) {
            return response()->json(['message' => 'Club is not available'], 422);
        }

        $existing = Client::query()
            ->where('tenant_id', $tenant->id)
            ->where('login', $login)
            ->first();

        if ($existing) {
            return response()->json(['ok' => true, 'message' => 'Already joined']);
        }

        $password = (string)($data['password'] ?? '');

        $client = Client::create([
            'tenant_id' => $tenant->id,
            'login' => $login,
            'password' => $password ? Hash::make($password) : null,
            'status' => 'active',
            'balance' => 0,
            'bonus' => 0,
        ]);

        return response()->json([
            'ok' => true,
            'tenant' => ['id' => $tenant->id, 'name' => $tenant->name],
            'client' => ['id' => $client->id, 'login' => $client->login],
        ]);
    }

    // GET /api/mobile/club/preview/{tenantId} (mobile.auth)
    public function preview(Request $request, int $tenantId)
    {
        $login = (string)$request->attributes->get('mobile_login');

        $client = Client::query()
            ->where('tenant_id', $tenantId)
            ->where('login', $login)
            ->where('status', 'active')
            ->first();

        if (!$client) {
            return response()->json(['message' => 'Account not found in this club'], 404);
        }

        if ($client->expires_at && $client->expires_at->isPast()) {
            return response()->json(['message' => 'Account expired'], 422);
        }

        return new MobileClubProfileResource(
            $this->buildClubProfile->execute($tenantId, (int) $client->id)
        );
    }

    // GET /api/mobile/club/profile (client.auth)
    public function profile(Request $request)
    {
        $tenantId = (int)$request->attributes->get('tenant_id');
        $clientId = (int)$request->attributes->get('client_id');

        return new MobileClubProfileResource(
            $this->buildClubProfile->execute($tenantId, $clientId)
        );
    }

    // GET /api/mobile/club/reviews (client.auth)
    public function reviews(Request $request)
    {
        $tenantId = (int)$request->attributes->get('tenant_id');
        $clientId = (int)$request->attributes->get('client_id');

        return new MobileClubReviewsResource(
            $this->listClubReviews->execute($tenantId, $clientId)
        );
    }

    // POST /api/mobile/club/reviews { atmosphere_rating, cleanliness_rating, technical_rating, peripherals_rating, comment? } (client.auth)
    public function saveReview(Request $request)
    {
        $tenantId = (int)$request->attributes->get('tenant_id');
        $clientId = (int)$request->attributes->get('client_id');

        $data = $request->validate([
            'rating' => ['nullable', 'integer', 'min:1', 'max:5'], // backward compatibility
            'atmosphere_rating' => ['nullable', 'integer', 'min:1', 'max:5'],
            'cleanliness_rating' => ['nullable', 'integer', 'min:1', 'max:5'],
            'technical_rating' => ['nullable', 'integer', 'min:1', 'max:5'],
            'peripherals_rating' => ['nullable', 'integer', 'min:1', 'max:5'],
            'comment' => ['nullable', 'string', 'max:1000'],
        ]);

        return new MobileClubReviewResource(
            $this->saveClubReview->execute($tenantId, $clientId, $data)
        );
    }
}
