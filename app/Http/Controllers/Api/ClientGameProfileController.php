<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\ClientGameProfile;
use App\Models\Pc;
use App\Models\ShellGame;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class ClientGameProfileController extends Controller
{
    public function index(Request $request)
    {
        $tenantId = (int)$request->attributes->get('tenant_id');
        $clientId = (int)$request->attributes->get('client_id');

        $rows = ClientGameProfile::query()
            ->where('tenant_id', $tenantId)
            ->where('client_id', $clientId)
            ->orderByDesc('updated_at')
            ->get();

        return response()->json([
            'data' => $rows->map(fn(ClientGameProfile $row) => $this->profilePayload($request, $row)),
        ]);
    }

    public function show(Request $request, string $gameSlug)
    {
        $tenantId = (int)$request->attributes->get('tenant_id');
        $clientId = (int)$request->attributes->get('client_id');
        $slug = $this->normalizeSlug($gameSlug);

        $row = ClientGameProfile::query()
            ->where('tenant_id', $tenantId)
            ->where('client_id', $clientId)
            ->where('game_slug', $slug)
            ->first();

        if (!$row) {
            return response()->json(['message' => 'Profile not found'], 404);
        }

        return response()->json([
            'data' => $this->profilePayload($request, $row),
        ]);
    }

    public function upsert(Request $request, string $gameSlug)
    {
        $tenantId = (int)$request->attributes->get('tenant_id');
        $clientId = (int)$request->attributes->get('client_id');
        $slug = $this->normalizeSlug($gameSlug);

        $request->validate([
            'pc_code' => ['nullable', 'string', 'max:64'],
            'archive' => ['nullable', 'file', 'max:102400'], // 100 MB
            'profile_json' => ['nullable'],
            'mouse_json' => ['nullable'],
        ]);

        $profileJson = $this->parseJsonField($request->input('profile_json'));
        $mouseJson = $this->parseJsonField($request->input('mouse_json'));

        if ($profileJson === '__invalid_json__') {
            throw ValidationException::withMessages([
                'profile_json' => 'Invalid JSON',
            ]);
        }
        if ($mouseJson === '__invalid_json__') {
            throw ValidationException::withMessages([
                'mouse_json' => 'Invalid JSON',
            ]);
        }

        if ($profileJson !== null && !is_array($profileJson)) {
            throw ValidationException::withMessages([
                'profile_json' => 'profile_json must be object/array JSON',
            ]);
        }
        if ($mouseJson !== null && !is_array($mouseJson)) {
            throw ValidationException::withMessages([
                'mouse_json' => 'mouse_json must be object/array JSON',
            ]);
        }

        $pcId = null;
        $pcCode = trim((string)$request->input('pc_code', ''));
        if ($pcCode !== '') {
            $pcId = (int)(Pc::query()
                ->where('tenant_id', $tenantId)
                ->where('code', $pcCode)
                ->value('id') ?? 0);
        }

        $existing = ClientGameProfile::query()
            ->where('tenant_id', $tenantId)
            ->where('client_id', $clientId)
            ->where('game_slug', $slug)
            ->first();

        $archivePath = $existing?->archive_path;
        $archiveSize = $existing?->archive_size;
        $archiveSha1 = $existing?->archive_sha1;

        if ($request->hasFile('archive')) {
            $file = $request->file('archive');
            $archivePath = $file->store("game-profiles/{$tenantId}/{$clientId}/{$slug}", 'local');
            $archiveSize = (int)($file->getSize() ?? 0);
            $archiveSha1 = sha1_file($file->getRealPath()) ?: null;
        }

        // If game is in catalog we mark as known; custom slug is also allowed.
        $knownGame = ShellGame::query()
            ->where('tenant_id', $tenantId)
            ->where('slug', $slug)
            ->exists();

        $row = $existing ?: new ClientGameProfile();
        $row->tenant_id = $tenantId;
        $row->client_id = $clientId;
        $row->game_slug = $slug;
        if ($profileJson !== null) {
            $row->profile_json = $profileJson;
        }
        if ($mouseJson !== null) {
            $row->mouse_json = $mouseJson;
        }
        $row->archive_path = $archivePath;
        $row->archive_size = $archiveSize;
        $row->archive_sha1 = $archiveSha1;
        $row->version = $existing ? ((int)$existing->version + 1) : 1;
        $row->last_pc_id = $pcId > 0 ? $pcId : ($existing?->last_pc_id);
        $row->last_synced_at = now();
        $row->save();

        return response()->json([
            'data' => array_merge(
                $this->profilePayload($request, $row),
                ['known_game' => $knownGame],
            ),
        ], $existing ? 200 : 201);
    }

    public function download(Request $request, string $gameSlug)
    {
        $tenantId = (int)$request->attributes->get('tenant_id');
        $clientId = (int)$request->attributes->get('client_id');
        $slug = $this->normalizeSlug($gameSlug);

        $row = ClientGameProfile::query()
            ->where('tenant_id', $tenantId)
            ->where('client_id', $clientId)
            ->where('game_slug', $slug)
            ->first();

        if (!$row || !$row->archive_path) {
            return response()->json(['message' => 'Archive not found'], 404);
        }

        if (!Storage::disk('local')->exists($row->archive_path)) {
            return response()->json(['message' => 'Archive missing on disk'], 404);
        }

        $client = Client::query()
            ->where('tenant_id', $tenantId)
            ->find($clientId, ['id', 'login']);
        $login = $client?->login ?: ('client_' . $clientId);
        $downloadName = sprintf('%s_%s_v%d.zip', $login, $slug, (int)$row->version);

        return Storage::disk('local')->download(
            $row->archive_path,
            $downloadName,
            ['Content-Type' => 'application/zip']
        );
    }

    private function normalizeSlug(string $slug): string
    {
        $value = strtolower(trim($slug));
        if ($value === '' || !preg_match('/^[a-z0-9][a-z0-9._-]{0,63}$/', $value)) {
            throw ValidationException::withMessages([
                'game_slug' => 'Invalid game slug',
            ]);
        }
        return $value;
    }

    private function parseJsonField(mixed $value): mixed
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_array($value)) {
            return $value;
        }
        if (!is_string($value)) {
            return $value;
        }

        $decoded = json_decode($value, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            return $decoded;
        }
        return '__invalid_json__';
    }

    private function profilePayload(Request $request, ClientGameProfile $row): array
    {
        $base = rtrim((string)(config('app.url') ?: $request->getSchemeAndHttpHost()), '/');
        return [
            'id' => (int)$row->id,
            'game_slug' => (string)$row->game_slug,
            'version' => (int)$row->version,
            'profile_json' => $row->profile_json,
            'mouse_json' => $row->mouse_json,
            'has_archive' => (bool)$row->archive_path,
            'archive_size' => $row->archive_size ? (int)$row->archive_size : null,
            'archive_sha1' => $row->archive_sha1,
            'last_pc_id' => $row->last_pc_id ? (int)$row->last_pc_id : null,
            'last_synced_at' => optional($row->last_synced_at)->toIso8601String(),
            'updated_at' => optional($row->updated_at)->toIso8601String(),
            'download_url' => $row->archive_path ? ($base . '/api/client/game-profiles/' . rawurlencode($row->game_slug) . '/download') : null,
        ];
    }
}
