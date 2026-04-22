<?php

namespace App\Services;

use App\Models\Client;
use App\Models\ClubReview;
use App\Models\MobileToken;
use App\Models\MobileUser;
use App\Models\Pc;
use App\Models\Setting;
use App\Models\Tenant;
use App\Models\Zone;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class MobileAuthService
{
    public function __construct(
        private readonly ClientTokenService $clientTokens,
    ) {
    }

    public function register(array $attributes): array
    {
        $login = trim((string) $attributes['login']);

        if (MobileUser::query()->where('login', $login)->exists()) {
            throw ValidationException::withMessages([
                'login' => 'Account already exists. Use login.',
            ]);
        }

        if (Client::query()->where('login', $login)->exists()) {
            throw ValidationException::withMessages([
                'login' => 'This login already exists in a club. Use login instead of register.',
            ]);
        }

        $mobileUser = MobileUser::query()->create([
            'login' => $login,
            'password_hash' => Hash::make((string) $attributes['password']),
        ]);

        return [
            'token' => $this->issueMobileToken((int) $mobileUser->id),
            'user' => $this->mobileUserPayload($mobileUser),
            'clubs' => [],
        ];
    }

    public function login(array $attributes): array
    {
        $login = (string) $attributes['login'];
        $password = (string) $attributes['password'];

        $mobileUser = MobileUser::query()->where('login', $login)->first();
        if ($mobileUser && !Hash::check($password, (string) $mobileUser->password_hash)) {
            throw ValidationException::withMessages([
                'login' => 'Неверный логин или пароль',
            ]);
        }

        if (!$mobileUser) {
            $clients = Client::query()
                ->where('login', $login)
                ->whereNotNull('password')
                ->get();

            $matched = null;
            foreach ($clients as $client) {
                if ($client->password && Hash::check($password, $client->password)) {
                    $matched = $client;
                    break;
                }
            }

            if (!$matched) {
                throw ValidationException::withMessages([
                    'login' => 'Неверный логин или пароль',
                ]);
            }

            $mobileUser = MobileUser::query()->create([
                'login' => $login,
                'password_hash' => Hash::make($password),
            ]);
        }

        return [
            'token' => $this->issueMobileToken((int) $mobileUser->id),
            'user' => $this->mobileUserPayload($mobileUser),
            'clubs' => $this->clubsForLogin($login),
        ];
    }

    public function me(int $mobileUserId): array
    {
        $mobileUser = MobileUser::query()->findOrFail($mobileUserId);

        return [
            'user' => $this->mobileUserPayload($mobileUser),
            'clubs' => $this->clubsForLogin((string) $mobileUser->login),
        ];
    }

    public function profile(int $mobileUserId): array
    {
        $mobileUser = MobileUser::query()->findOrFail($mobileUserId);

        return [
            'user' => $this->mobileUserPayload($mobileUser),
        ];
    }

    public function saveProfile(int $mobileUserId, array $attributes): array
    {
        $mobileUser = MobileUser::query()->findOrFail($mobileUserId);
        $mobileUser->first_name = $this->nullableText($attributes['first_name'] ?? null);
        $mobileUser->last_name = $this->nullableText($attributes['last_name'] ?? null);
        $mobileUser->avatar_url = $this->nullableText($attributes['avatar_url'] ?? null);
        $mobileUser->save();

        return [
            'ok' => true,
            'user' => $this->mobileUserPayload($mobileUser),
        ];
    }

    public function uploadAvatar(int $mobileUserId, UploadedFile $file): array
    {
        $mobileUser = MobileUser::query()->findOrFail($mobileUserId);
        $path = $file->store('mobile_avatars/' . $mobileUserId, 'public');
        $mobileUser->avatar_url = Storage::disk('public')->url($path);
        $mobileUser->save();

        return [
            'ok' => true,
            'user' => $this->mobileUserPayload($mobileUser),
        ];
    }

    public function switchClub(string $mobileLogin, int $tenantId): array
    {
        $client = Client::query()
            ->where('tenant_id', $tenantId)
            ->where('login', $mobileLogin)
            ->first();

        if (!$client) {
            return ['message' => 'Аккаунт в этом клубе не найден', 'status_code' => 404];
        }
        if ($client->status !== 'active') {
            return ['message' => 'Аккаунт заблокирован', 'status_code' => 422];
        }
        if ($client->expires_at && $client->expires_at->isPast()) {
            return ['message' => 'Аккаунт истёк', 'status_code' => 422];
        }

        $issued = $this->clientTokens->issue(
            $tenantId,
            (int) $client->id,
            now()->addHours((int) config('domain.auth.club_switch_token_ttl_hours', 24)),
        );

        $tenant = Tenant::query()->find($tenantId);

        return [
            'club_token' => $issued['plain'],
            'tenant' => [
                'id' => $tenantId,
                'name' => $tenant->name ?? ('Club #' . $tenantId),
            ],
            'client' => [
                'id' => (int) $client->id,
                'login' => (string) $client->login,
                'balance' => (int) $client->balance,
                'bonus' => (int) $client->bonus,
            ],
        ];
    }

    public function logout(?string $bearer): void
    {
        $plain = trim((string) $bearer);
        if ($plain === '') {
            return;
        }

        MobileToken::query()->where('token_hash', hash('sha256', $plain))->delete();
    }

    private function clubsForLogin(string $login): array
    {
        $clients = Client::query()
            ->where('login', $login)
            ->where('status', 'active')
            ->get(['id', 'tenant_id', 'login', 'balance', 'bonus']);

        if ($clients->isEmpty()) {
            return [];
        }

        $tenantIds = $clients->pluck('tenant_id')->unique()->values()->all();
        $tenants = Tenant::query()
            ->whereIn('id', $tenantIds)
            ->get(['id', 'name', 'status'])
            ->keyBy('id');

        $settingsRows = Setting::query()
            ->whereIn('tenant_id', $tenantIds)
            ->whereIn('key', ['club_name', 'club_logo', 'club_location'])
            ->get(['tenant_id', 'key', 'value']);

        $settingsByTenant = [];
        foreach ($settingsRows as $row) {
            $settingsByTenant[(int) $row->tenant_id][$row->key] = $row->value;
        }

        $pcsByTenant = Pc::query()
            ->whereIn('tenant_id', $tenantIds)
            ->selectRaw('tenant_id, COUNT(*) as total')
            ->groupBy('tenant_id')
            ->pluck('total', 'tenant_id')
            ->toArray();

        $zonesByTenant = Zone::query()
            ->whereIn('tenant_id', $tenantIds)
            ->where('is_active', true)
            ->selectRaw('tenant_id, COUNT(*) as total')
            ->groupBy('tenant_id')
            ->pluck('total', 'tenant_id')
            ->toArray();

        $reviewsByTenant = [];
        $reviewRows = ClubReview::query()
            ->whereIn('tenant_id', $tenantIds)
            ->selectRaw('tenant_id, COUNT(*) as total, COALESCE(AVG(rating), 0) as avg_rating')
            ->groupBy('tenant_id')
            ->get();

        foreach ($reviewRows as $row) {
            $reviewsByTenant[(int) $row->tenant_id] = [
                'total' => (int) ($row->total ?? 0),
                'avg_rating' => round((float) ($row->avg_rating ?? 0), 2),
            ];
        }

        $items = [];
        foreach ($clients as $client) {
            $tenant = $tenants[$client->tenant_id] ?? null;
            if (!$tenant || ($tenant->status ?? null) !== 'active') {
                continue;
            }

            $tenantId = (int) $client->tenant_id;
            $config = $settingsByTenant[$tenantId] ?? [];
            $clubName = $this->settingString($config['club_name'] ?? null) ?: (string) ($tenant->name ?? ('Club #' . $tenantId));
            $clubLogo = $this->settingString($config['club_logo'] ?? null);
            if ($clubLogo && !Str::startsWith($clubLogo, ['http://', 'https://', 'data:'])) {
                $clubLogo = url(Str::startsWith($clubLogo, '/') ? $clubLogo : ('/' . ltrim($clubLogo, '/')));
            }

            $review = $reviewsByTenant[$tenantId] ?? ['total' => 0, 'avg_rating' => 0];

            $items[] = [
                'tenant_id' => $tenantId,
                'tenant_name' => $clubName,
                'client_id' => (int) $client->id,
                'login' => (string) $client->login,
                'balance' => (int) $client->balance,
                'bonus' => (int) $client->bonus,
                'club_logo' => $clubLogo,
                'club_location' => $this->settingArray($config['club_location'] ?? null),
                'pcs_total' => (int) ($pcsByTenant[$tenantId] ?? 0),
                'zones_total' => (int) ($zonesByTenant[$tenantId] ?? 0),
                'reviews_count' => (int) ($review['total'] ?? 0),
                'avg_rating' => (float) ($review['avg_rating'] ?? 0),
            ];
        }

        usort($items, fn(array $left, array $right) => $right['balance'] <=> $left['balance']);

        return $items;
    }

    private function mobileUserPayload(MobileUser $mobileUser): array
    {
        return [
            'id' => (int) $mobileUser->id,
            'login' => (string) $mobileUser->login,
            'first_name' => $this->nullableText($mobileUser->first_name),
            'last_name' => $this->nullableText($mobileUser->last_name),
            'avatar_url' => $this->nullableText($mobileUser->avatar_url),
        ];
    }

    private function settingString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (is_array($value)) {
            foreach (['url', 'path', 'value', 'text', 'logo'] as $key) {
                $candidate = isset($value[$key]) ? trim((string) $value[$key]) : '';
                if ($candidate !== '') {
                    return $candidate;
                }
            }

            return null;
        }

        if (is_string($value)) {
            $trimmed = trim($value);
            if (strlen($trimmed) >= 2 && $trimmed[0] === '"' && substr($trimmed, -1) === '"') {
                $decoded = json_decode($trimmed, true);
                if (is_string($decoded)) {
                    $decoded = trim($decoded);
                    return $decoded === '' ? null : $decoded;
                }
            }

            return $trimmed === '' ? null : $trimmed;
        }

        if (is_scalar($value)) {
            $trimmed = trim((string) $value);
            return $trimmed === '' ? null : $trimmed;
        }

        return null;
    }

    private function settingArray(mixed $value): ?array
    {
        if ($value === null) {
            return null;
        }
        if (is_array($value)) {
            return $value;
        }
        if (is_string($value)) {
            $trimmed = trim($value);
            if ($trimmed === '') {
                return null;
            }

            $decoded = json_decode($trimmed, true);
            return is_array($decoded) ? $decoded : null;
        }

        return null;
    }

    private function nullableText(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $text = trim((string) $value);
        return $text === '' ? null : $text;
    }

    private function issueMobileToken(int $mobileUserId): string
    {
        $plain = Str::random(48);

        MobileToken::query()->create([
            'mobile_user_id' => $mobileUserId,
            'token_hash' => hash('sha256', $plain),
            'expires_at' => now()->addDays(30),
            'last_used_at' => now(),
        ]);

        return $plain;
    }
}
