<?php

namespace App\Http\Controllers\Api;

use App\Actions\ClientAuth\BuyClientShellPackageAction;
use App\Actions\ClientAuth\GetClientShellStateAction;
use App\Actions\ClientAuth\LoginClientShellAction;
use App\Actions\ClientAuth\LogoutClientShellAction;
use App\Actions\ClientAuth\StartClientShellSessionAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\ClientShellBuyPackageRequest;
use App\Http\Requests\Api\ClientPublicSettingsRequest;
use App\Http\Requests\Api\ClientShellLoginRequest;
use App\Http\Requests\Api\ClientShellLogoutRequest;
use App\Http\Requests\Api\ClientShellStartSessionRequest;
use App\Http\Requests\Api\ClientShellStateRequest;
use App\Http\Resources\ClientAuth\ClientShellLoginResource;
use App\Http\Resources\ClientAuth\ClientShellStateResource;
use App\Models\Client;
use App\Models\LicenseKey;
use App\Models\Pc;
use App\Services\TenantSettingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ClientAuthController extends Controller
{
    public function __construct(
        private readonly LoginClientShellAction $loginClientShell,
        private readonly StartClientShellSessionAction $startClientShellSession,
        private readonly BuyClientShellPackageAction $buyClientShellPackage,
        private readonly GetClientShellStateAction $getClientShellState,
        private readonly LogoutClientShellAction $logoutClientShell,
        private readonly TenantSettingService $settings,
    ) {
    }

    public function login(ClientShellLoginRequest $request)
    {
        return new ClientShellLoginResource($this->loginClientShell->execute($request->payload()));
    }

    public function startSession(ClientShellStartSessionRequest $request)
    {
        return new ClientShellLoginResource(
            $this->startClientShellSession->execute(
                $request->licenseKey(),
                $request->pcCode(),
                $request->bearerToken(),
                $request->source(),
                $request->clientPackageId(),
            )
        );
    }

    public function buyPackage(ClientShellBuyPackageRequest $request)
    {
        return response()->json(
            $this->buyClientShellPackage->execute(
                $request->licenseKey(),
                $request->pcCode(),
                $request->bearerToken(),
                $request->packageId(),
            )
        );
    }

    public function publicSettings(ClientPublicSettingsRequest $request)
    {
        $license = LicenseKey::with('tenant')
            ->where('key', $request->licenseKey())
            ->first();

        if (!$license || $license->status !== 'active' || ($license->expires_at && $license->expires_at->isPast())) {
            return response()->json(['message' => 'License invalid'], 403);
        }
        if ($license->tenant?->status !== 'active') {
            return response()->json(['message' => 'Tenant blocked'], 403);
        }

        $tenantId = $license->tenant_id;

        $pc = Pc::where('tenant_id', $tenantId)
            ->where('code', $request->pcCode())
            ->first();

        if (!$pc) {
            return response()->json(['message' => 'PC not found'], 404);
        }

        $promoUrl = $this->settings->get($tenantId, 'promo_video_url', null);
        $promoUrl = $this->normalizePromoUrl($promoUrl, $request);
        if (is_string($promoUrl)) {
            $this->settings->set($tenantId, 'promo_video_url', $promoUrl);
        }

        return response()->json([
            'ok' => true,
            'settings' => [
                'promo_video_url' => $promoUrl,
            ],
        ]);
    }

    private function normalizePromoUrl(mixed $value, Request $request): mixed
    {
        if (!is_string($value)) {
            return $value;
        }

        $url = trim($value);
        if ($url === '') {
            return $value;
        }

        $base = rtrim($request->getSchemeAndHttpHost(), '/');
        if (str_starts_with($url, '/')) {
            return $base . $url;
        }

        $fixed = preg_replace('#^https?://(localhost|127\\.0\\.0\\.1)(:\\d+)?#i', $base, $url);
        return $fixed ?: $url;
    }

    public function state(ClientShellStateRequest $request)
    {
        return new ClientShellStateResource(
            $this->getClientShellState->execute(
                $request->licenseKey(),
                $request->pcCode(),
                $request->bearerToken(),
            )
        );
    }

    public function logout(ClientShellLogoutRequest $request)
    {
        $this->logoutClientShell->execute(
            $request->licenseKey(),
            $request->pcCode(),
            $request->bearerToken(),
        );

        return response()->json(['ok' => true]);
    }

    public function me(Request $request)
    {
        $tenantId = (int) $request->attributes->get('tenant_id');
        $clientId = (int) $request->attributes->get('client_id');

        $client = Client::query()
            ->where('tenant_id', $tenantId)
            ->findOrFail($clientId);

        return response()->json([
            'data' => [
                'id' => (int) $client->id,
                'account_id' => $client->account_id,
                'login' => $client->login,
                'phone' => $client->phone,
                'username' => $client->username,
                'balance' => (int) $client->balance,
                'bonus' => (int) $client->bonus,
                'status' => $client->status,
                'expires_at' => optional($client->expires_at)->toIso8601String(),
            ],
        ]);
    }

    public function updateAccount(Request $request)
    {
        $tenantId = (int) $request->attributes->get('tenant_id');
        $clientId = (int) $request->attributes->get('client_id');

        $client = Client::query()
            ->where('tenant_id', $tenantId)
            ->findOrFail($clientId);

        $data = $request->validate([
            'login' => [
                'sometimes',
                'required',
                'string',
                'min:3',
                'max:64',
                'regex:/^[A-Za-z0-9_\-.]+$/',
                Rule::unique('clients', 'login')
                    ->where(fn ($query) => $query->where('tenant_id', $tenantId))
                    ->ignore($client->id),
            ],
            'username' => ['sometimes', 'nullable', 'string', 'max:64'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:32'],
            'current_password' => ['nullable', 'string', 'max:255'],
            'password' => ['nullable', 'string', 'min:4', 'max:255', 'confirmed'],
        ]);

        $loginChanged = array_key_exists('login', $data)
            && trim((string) $data['login']) !== (string) $client->login;
        $passwordChanged = !empty($data['password']);

        if ($loginChanged || $passwordChanged) {
            $currentPassword = (string) ($data['current_password'] ?? '');
            if (!$client->password || !Hash::check($currentPassword, (string) $client->password)) {
                throw ValidationException::withMessages([
                    'current_password' => 'Joriy parol noto\'g\'ri.',
                ]);
            }
        }

        if (array_key_exists('login', $data)) {
            $client->login = trim((string) $data['login']);
        }

        if (array_key_exists('username', $data)) {
            $client->username = $this->nullableTrim($data['username'] ?? null);
        }

        if (array_key_exists('phone', $data)) {
            $client->phone = $this->nullableTrim($data['phone'] ?? null);
        }

        if ($passwordChanged) {
            $client->password = Hash::make((string) $data['password']);
        }

        $client->save();

        return response()->json([
            'ok' => true,
            'data' => $this->accountPayload($client),
        ]);
    }

    private function accountPayload(Client $client): array
    {
        return [
            'id' => (int) $client->id,
            'account_id' => $client->account_id,
            'login' => $client->login,
            'phone' => $client->phone,
            'username' => $client->username,
            'balance' => (int) $client->balance,
            'bonus' => (int) $client->bonus,
            'status' => $client->status,
            'expires_at' => optional($client->expires_at)->toIso8601String(),
        ];
    }

    private function nullableTrim(mixed $value): ?string
    {
        $text = trim((string) ($value ?? ''));
        return $text === '' ? null : $text;
    }
}
