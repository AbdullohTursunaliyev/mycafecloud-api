<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\ClubReview;
use App\Models\ClientToken;
use App\Models\MobileToken;
use App\Models\MobileUser;
use App\Models\Pc;
use App\Models\Setting;
use App\Models\Tenant;
use App\Models\Zone;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class MobileAuthController extends Controller
{
    // POST /api/mobile/auth/register  { login, password, password_confirmation }
    public function register(Request $request)
    {
        $data = $request->validate([
            'login' => ['required', 'string', 'min:3', 'max:64', 'regex:/^[A-Za-z0-9_\\-.]+$/'],
            'password' => ['required', 'string', 'min:6', 'max:255', 'confirmed'],
        ]);

        $login = trim((string)$data['login']);

        $exists = MobileUser::where('login', $login)->exists();
        if ($exists) {
            throw ValidationException::withMessages([
                'login' => 'Account already exists. Use login.',
            ]);
        }

        // If this login already exists in clubs, onboarding must go via login
        // to prove the club password and prevent account hijacking.
        $clubAccountExists = Client::where('login', $login)->exists();
        if ($clubAccountExists) {
            throw ValidationException::withMessages([
                'login' => 'This login already exists in a club. Use login instead of register.',
            ]);
        }

        $mobileUser = MobileUser::create([
            'login' => $login,
            'password_hash' => Hash::make((string)$data['password']),
        ]);

        $plain = $this->issueMobileToken($mobileUser->id);

        return response()->json([
            'token' => $plain,
            'user' => $this->mobileUserPayload($mobileUser),
            'clubs' => [],
        ], 201);
    }

    // POST /api/mobile/auth/login  { login, password }
    public function login(Request $request)
    {
        $data = $request->validate([
            'login' => ['required','string','max:64'],
            'password' => ['required','string','max:255'],
        ]);

        $login = $data['login'];
        $password = $data['password'];

        $mobileUser = MobileUser::where('login', $login)->first();

        // 1) agar bor bo‘lsa - password tekshir
        if ($mobileUser) {
            if (!Hash::check($password, $mobileUser->password_hash)) {
                throw ValidationException::withMessages(['login' => 'Неверный логин или пароль']);
            }
        }

        // 2) bo‘lmasa: kamida 1 klubda login+password isbot
        if (!$mobileUser) {
            $clients = Client::query()
                ->where('login', $login)
                ->whereNotNull('password')
                ->get();

            $ok = null;
            foreach ($clients as $c) {
                if ($c->password && Hash::check($password, $c->password)) {
                    $ok = $c; break;
                }
            }

            if (!$ok) {
                throw ValidationException::withMessages(['login' => 'Неверный логин или пароль']);
            }

            $mobileUser = MobileUser::create([
                'login' => $login,
                'password_hash' => Hash::make($password),
            ]);
        }

        $plain = $this->issueMobileToken($mobileUser->id);

        return response()->json([
            'token' => $plain,
            'user' => $this->mobileUserPayload($mobileUser),
            'clubs' => $this->clubsForLogin($login),
        ]);
    }

    // GET /api/mobile/auth/me
    public function me(Request $request)
    {
        $mobileUserId = (int)$request->attributes->get('mobile_user_id');
        $mobileUser = MobileUser::query()->findOrFail($mobileUserId);
        $login = (string)$mobileUser->login;
        return response()->json([
            'user' => $this->mobileUserPayload($mobileUser),
            'clubs' => $this->clubsForLogin($login),
        ]);
    }

    // GET /api/mobile/auth/profile
    public function profile(Request $request)
    {
        $mobileUserId = (int)$request->attributes->get('mobile_user_id');
        $mobileUser = MobileUser::query()->findOrFail($mobileUserId);

        return response()->json([
            'user' => $this->mobileUserPayload($mobileUser),
        ]);
    }

    // POST /api/mobile/auth/profile
    public function saveProfile(Request $request)
    {
        $profileColumnsReady =
            Schema::hasColumn('mobile_users', 'first_name') &&
            Schema::hasColumn('mobile_users', 'last_name') &&
            Schema::hasColumn('mobile_users', 'avatar_url');
        if (!$profileColumnsReady) {
            return response()->json([
                'message' => 'Profile fields are not ready. Run migration first.',
            ], 422);
        }

        $mobileUserId = (int)$request->attributes->get('mobile_user_id');
        $mobileUser = MobileUser::query()->findOrFail($mobileUserId);

        $data = $request->validate([
            'first_name' => ['nullable', 'string', 'max:64'],
            'last_name' => ['nullable', 'string', 'max:64'],
            'avatar_url' => ['nullable', 'string', 'max:2000'],
        ]);

        $mobileUser->first_name = $this->nullableText($data['first_name'] ?? null);
        $mobileUser->last_name = $this->nullableText($data['last_name'] ?? null);
        $mobileUser->avatar_url = $this->nullableText($data['avatar_url'] ?? null);
        $mobileUser->save();

        return response()->json([
            'ok' => true,
            'user' => $this->mobileUserPayload($mobileUser),
        ]);
    }

    // POST /api/mobile/auth/profile/avatar (multipart: avatar)
    public function uploadAvatar(Request $request)
    {
        $profileColumnsReady =
            Schema::hasColumn('mobile_users', 'first_name') &&
            Schema::hasColumn('mobile_users', 'last_name') &&
            Schema::hasColumn('mobile_users', 'avatar_url');
        if (!$profileColumnsReady) {
            return response()->json([
                'message' => 'Profile fields are not ready. Run migration first.',
            ], 422);
        }

        $request->validate([
            'avatar' => ['required', 'image', 'max:5120'], // up to 5MB
        ]);

        $mobileUserId = (int)$request->attributes->get('mobile_user_id');
        $mobileUser = MobileUser::query()->findOrFail($mobileUserId);

        $file = $request->file('avatar');
        $path = $file->store('mobile_avatars/' . $mobileUserId, 'public');
        $mobileUser->avatar_url = Storage::disk('public')->url($path);
        $mobileUser->save();

        return response()->json([
            'ok' => true,
            'user' => $this->mobileUserPayload($mobileUser),
        ]);
    }

    // POST /api/mobile/auth/switch-club { tenant_id }
    public function switchClub(Request $request)
    {
        $data = $request->validate([
            'tenant_id' => ['required','integer','min:1'],
        ]);

        $tenantId = (int)$data['tenant_id'];
        $login = (string)$request->attributes->get('mobile_login');

        $client = Client::query()
            ->where('tenant_id', $tenantId)
            ->where('login', $login)
            ->first();

        if (!$client) return response()->json(['message' => 'Аккаунт в этом клубе не найден'], 404);
        if ($client->status !== 'active') return response()->json(['message' => 'Аккаунт заблокирован'], 422);
        if ($client->expires_at && $client->expires_at->isPast()) return response()->json(['message' => 'Аккаунт истёк'], 422);

        $plain = Str::random(48);
        ClientToken::create([
            'tenant_id' => $tenantId,
            'client_id' => $client->id,
            'token_hash' => hash('sha256', $plain),
            'expires_at' => now()->addHours(24),
            'last_used_at' => now(),
        ]);

        $tenant = Tenant::query()->find($tenantId);

        return response()->json([
            'club_token' => $plain,
            'tenant' => [
                'id' => $tenantId,
                'name' => $tenant->name ?? ('Club #' . $tenantId),
            ],
            'client' => [
                'id' => $client->id,
                'login' => $client->login,
                'balance' => (int)$client->balance,
                'bonus' => (int)$client->bonus,
            ],
        ]);
    }

    // POST /api/mobile/auth/logout
    public function logout(Request $request)
    {
        $auth = (string)$request->header('Authorization', '');
        $plain = trim(substr($auth, 7));
        $hash = hash('sha256', $plain);
        MobileToken::where('token_hash', $hash)->delete();
        return response()->json(['ok' => true]);
    }

    private function clubsForLogin(string $login): array
    {
        $clients = Client::query()
            ->where('login', $login)
            ->where('status', 'active')
            ->get(['id','tenant_id','login','balance','bonus']);

        if ($clients->isEmpty()) return [];

        $tenantIds = $clients->pluck('tenant_id')->unique()->values()->all();
        $tenants = Tenant::query()
            ->whereIn('id', $tenantIds)
            ->get(['id','name','status'])
            ->keyBy('id');

        $settingsRows = Setting::query()
            ->whereIn('tenant_id', $tenantIds)
            ->whereIn('key', ['club_name', 'club_logo', 'club_location'])
            ->get(['tenant_id', 'key', 'value']);

        $settingsByTenant = [];
        foreach ($settingsRows as $row) {
            $tid = (int)$row->tenant_id;
            if (!isset($settingsByTenant[$tid])) {
                $settingsByTenant[$tid] = [];
            }
            $settingsByTenant[$tid][$row->key] = $row->value;
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
        if (Schema::hasTable('club_reviews')) {
            $rows = ClubReview::query()
                ->whereIn('tenant_id', $tenantIds)
                ->selectRaw('tenant_id, COUNT(*) as total, COALESCE(AVG(rating), 0) as avg_rating')
                ->groupBy('tenant_id')
                ->get();

            foreach ($rows as $row) {
                $reviewsByTenant[(int)$row->tenant_id] = [
                    'total' => (int)($row->total ?? 0),
                    'avg_rating' => round((float)($row->avg_rating ?? 0), 2),
                ];
            }
        }

        $out = [];
        foreach ($clients as $c) {
            $t = $tenants[$c->tenant_id] ?? null;
            if (!$t || ($t->status ?? null) !== 'active') continue;

            $tenantId = (int)$c->tenant_id;
            $cfg = $settingsByTenant[$tenantId] ?? [];
            $clubName = $this->settingString($cfg['club_name'] ?? null) ?: (string)($t->name ?? ('Club #' . $tenantId));
            $clubLogo = $this->settingString($cfg['club_logo'] ?? null);
            if ($clubLogo && !Str::startsWith($clubLogo, ['http://', 'https://', 'data:'])) {
                $clubLogo = url(Str::startsWith($clubLogo, '/') ? $clubLogo : ('/' . ltrim($clubLogo, '/')));
            }
            $clubLocation = $this->settingArray($cfg['club_location'] ?? null);
            $review = $reviewsByTenant[$tenantId] ?? ['total' => 0, 'avg_rating' => 0];

            $out[] = [
                'tenant_id' => $tenantId,
                'tenant_name' => $clubName,
                'client_id' => (int)$c->id,
                'login' => (string)$c->login,
                'balance' => (int)$c->balance,
                'bonus' => (int)$c->bonus,
                'club_logo' => $clubLogo,
                'club_location' => $clubLocation,
                'pcs_total' => (int)($pcsByTenant[$tenantId] ?? 0),
                'zones_total' => (int)($zonesByTenant[$tenantId] ?? 0),
                'reviews_count' => (int)($review['total'] ?? 0),
                'avg_rating' => (float)($review['avg_rating'] ?? 0),
            ];
        }

        usort($out, fn($a,$b) => ($b['balance'] <=> $a['balance']));
        return $out;
    }

    private function settingString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (is_array($value)) {
            foreach (['url', 'path', 'value', 'text', 'logo'] as $key) {
                $v = isset($value[$key]) ? trim((string)$value[$key]) : '';
                if ($v !== '') {
                    return $v;
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
            $trimmed = trim((string)$value);
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

    private function mobileUserPayload(MobileUser $mobileUser): array
    {
        return [
            'id' => (int)$mobileUser->id,
            'login' => (string)$mobileUser->login,
            'first_name' => $this->nullableText($mobileUser->first_name),
            'last_name' => $this->nullableText($mobileUser->last_name),
            'avatar_url' => $this->nullableText($mobileUser->avatar_url),
        ];
    }

    private function nullableText(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $text = trim((string)$value);
        return $text === '' ? null : $text;
    }

    private function issueMobileToken(int $mobileUserId): string
    {
        $plain = Str::random(48);

        MobileToken::create([
            'mobile_user_id' => $mobileUserId,
            'token_hash' => hash('sha256', $plain),
            'expires_at' => now()->addDays(30),
            'last_used_at' => now(),
        ]);

        return $plain;
    }
}
