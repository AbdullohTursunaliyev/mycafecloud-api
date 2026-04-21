<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ClientHubProfile;
use Illuminate\Http\Request;

class ClientHubProfileController extends Controller
{
    private const RECENT_LIMIT = 12;
    private const FAVORITES_LIMIT = 24;

    public function show(Request $request)
    {
        $tenantId = (int)$request->attributes->get('tenant_id');
        $clientId = (int)$request->attributes->get('client_id');

        $row = ClientHubProfile::query()
            ->where('tenant_id', $tenantId)
            ->where('client_id', $clientId)
            ->first();

        return response()->json([
            'data' => $this->payload($row),
        ]);
    }

    public function upsert(Request $request)
    {
        $tenantId = (int)$request->attributes->get('tenant_id');
        $clientId = (int)$request->attributes->get('client_id');

        $validated = $request->validate([
            'recent' => ['nullable', 'array', 'max:' . self::RECENT_LIMIT],
            'recent.*' => ['string', 'max:64', 'regex:/^[a-zA-Z0-9._-]+$/'],
            'favorites' => ['nullable', 'array', 'max:' . self::FAVORITES_LIMIT],
            'favorites.*' => ['string', 'max:64', 'regex:/^[a-zA-Z0-9._-]+$/'],
            'last_pc_code' => ['nullable', 'string', 'max:64'],
        ]);

        $recent = $this->normalizeIds($validated['recent'] ?? [], self::RECENT_LIMIT);
        $favorites = $this->normalizeIds($validated['favorites'] ?? [], self::FAVORITES_LIMIT);
        $lastPcCode = trim((string)($validated['last_pc_code'] ?? ''));

        $row = ClientHubProfile::query()
            ->where('tenant_id', $tenantId)
            ->where('client_id', $clientId)
            ->first();

        if ($row) {
            $row->recent_json = $recent;
            $row->favorites_json = $favorites;
            $row->last_pc_code = $lastPcCode !== '' ? $lastPcCode : null;
            $row->version = ((int)$row->version) + 1;
            $row->save();
        } else {
            $row = ClientHubProfile::query()->create([
                'tenant_id' => $tenantId,
                'client_id' => $clientId,
                'recent_json' => $recent,
                'favorites_json' => $favorites,
                'last_pc_code' => $lastPcCode !== '' ? $lastPcCode : null,
                'version' => 1,
            ]);
        }

        return response()->json([
            'data' => $this->payload($row),
        ]);
    }

    private function normalizeIds(array $list, int $limit): array
    {
        $out = [];
        $seen = [];
        foreach ($list as $item) {
            $id = trim((string)$item);
            if ($id === '') {
                continue;
            }
            $key = strtolower($id);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $out[] = $id;
            if (count($out) >= $limit) {
                break;
            }
        }
        return $out;
    }

    private function payload(?ClientHubProfile $row): array
    {
        if (!$row) {
            return [
                'recent' => [],
                'favorites' => [],
                'version' => 0,
                'last_pc_code' => null,
                'updated_at' => null,
            ];
        }

        return [
            'recent' => is_array($row->recent_json) ? $row->recent_json : [],
            'favorites' => is_array($row->favorites_json) ? $row->favorites_json : [],
            'version' => (int)$row->version,
            'last_pc_code' => $row->last_pc_code,
            'updated_at' => optional($row->updated_at)->toIso8601String(),
        ];
    }
}

