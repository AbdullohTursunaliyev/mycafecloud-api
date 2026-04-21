<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\ClientGameProfile;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class AgentGameProfileController extends Controller
{
    public function pull(Request $request)
    {
        $tenantId = (int)$request->attributes->get('tenant_id');

        $data = $request->validate([
            'game_slug' => ['required', 'string', 'max:64'],
            'client_id' => ['nullable', 'integer', 'min:1'],
            'client_login' => ['nullable', 'string', 'max:64'],
        ]);

        $client = $this->resolveClient($tenantId, $data['client_id'] ?? null, $data['client_login'] ?? null);
        if (!$client) {
            return response()->json(['found' => false, 'message' => 'Client not found'], 404);
        }

        $slug = $this->normalizeSlug((string)$data['game_slug']);
        $row = ClientGameProfile::query()
            ->where('tenant_id', $tenantId)
            ->where('client_id', (int)$client->id)
            ->where('game_slug', $slug)
            ->first();

        if (!$row) {
            return response()->json(['found' => false, 'game_slug' => $slug]);
        }

        return response()->json([
            'found' => true,
            'data' => $this->payload($request, $row),
        ]);
    }

    public function push(Request $request)
    {
        $tenantId = (int)$request->attributes->get('tenant_id');
        $pcId = (int)$request->attributes->get('pc_id');

        $request->validate([
            'game_slug' => ['required', 'string', 'max:64'],
            'client_id' => ['nullable', 'integer', 'min:1'],
            'client_login' => ['nullable', 'string', 'max:64'],
            'archive' => ['nullable', 'file', 'max:102400'],
            'profile_json' => ['nullable'],
            'mouse_json' => ['nullable'],
        ]);

        $client = $this->resolveClient($tenantId, $request->input('client_id'), $request->input('client_login'));
        if (!$client) {
            throw ValidationException::withMessages([
                'client_login' => 'Client not found',
            ]);
        }

        $slug = $this->normalizeSlug((string)$request->input('game_slug'));
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

        $existing = ClientGameProfile::query()
            ->where('tenant_id', $tenantId)
            ->where('client_id', (int)$client->id)
            ->where('game_slug', $slug)
            ->first();

        $archivePath = $existing?->archive_path;
        $archiveSize = $existing?->archive_size;
        $archiveSha1 = $existing?->archive_sha1;

        if ($request->hasFile('archive')) {
            $file = $request->file('archive');
            $archivePath = $file->store("game-profiles/{$tenantId}/{$client->id}/{$slug}", 'local');
            $archiveSize = (int)($file->getSize() ?? 0);
            $archiveSha1 = sha1_file($file->getRealPath()) ?: null;
        }

        $row = $existing ?: new ClientGameProfile();
        $row->tenant_id = $tenantId;
        $row->client_id = (int)$client->id;
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
            'ok' => true,
            'data' => $this->payload($request, $row),
        ], $existing ? 200 : 201);
    }

    public function download(Request $request, int $id)
    {
        $tenantId = (int)$request->attributes->get('tenant_id');
        $row = ClientGameProfile::query()
            ->where('tenant_id', $tenantId)
            ->findOrFail($id);

        if (!$row->archive_path || !Storage::disk('local')->exists($row->archive_path)) {
            return response()->json(['message' => 'Archive not found'], 404);
        }

        $client = Client::query()->find($row->client_id, ['id', 'login']);
        $login = $client?->login ?: ('client_' . $row->client_id);
        $name = sprintf('%s_%s_v%d.zip', $login, $row->game_slug, (int)$row->version);

        return Storage::disk('local')->download(
            $row->archive_path,
            $name,
            ['Content-Type' => 'application/zip']
        );
    }

    private function payload(Request $request, ClientGameProfile $row): array
    {
        $base = rtrim((string)(config('app.url') ?: $request->getSchemeAndHttpHost()), '/');
        return [
            'id' => (int)$row->id,
            'client_id' => (int)$row->client_id,
            'game_slug' => (string)$row->game_slug,
            'version' => (int)$row->version,
            'profile_json' => $row->profile_json,
            'mouse_json' => $row->mouse_json,
            'has_archive' => (bool)$row->archive_path,
            'archive_size' => $row->archive_size ? (int)$row->archive_size : null,
            'archive_sha1' => $row->archive_sha1,
            'last_pc_id' => $row->last_pc_id ? (int)$row->last_pc_id : null,
            'last_synced_at' => optional($row->last_synced_at)->toIso8601String(),
            'download_url' => $row->archive_path ? ($base . '/api/agent/profiles/' . (int)$row->id . '/download') : null,
        ];
    }

    private function resolveClient(int $tenantId, mixed $clientId, mixed $clientLogin): ?Client
    {
        $id = (int)($clientId ?? 0);
        if ($id > 0) {
            return Client::query()
                ->where('tenant_id', $tenantId)
                ->find($id, ['id', 'login']);
        }

        $login = trim((string)($clientLogin ?? ''));
        if ($login === '') {
            return null;
        }

        return Client::query()
            ->where('tenant_id', $tenantId)
            ->where('login', $login)
            ->first(['id', 'login']);
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
}
