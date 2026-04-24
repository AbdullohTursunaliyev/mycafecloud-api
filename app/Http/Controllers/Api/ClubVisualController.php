<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\GenerateClubVisualDraftRequest;
use App\Http\Requests\Api\StoreClubVisualRequest;
use App\Http\Requests\Api\UpdateClubVisualRequest;
use App\Http\Resources\Admin\ClubVisualResource;
use App\Services\ClubVisualAiDraftService;
use App\Services\ClubVisualCatalogService;
use App\Services\TenantAssetService;
use Illuminate\Http\Request;

class ClubVisualController extends Controller
{
    public function __construct(
        private readonly ClubVisualCatalogService $visuals,
        private readonly TenantAssetService $assets,
        private readonly ClubVisualAiDraftService $aiDrafts,
    ) {
    }

    public function index(Request $request)
    {
        $tenantId = (int) $request->user()->tenant_id;

        return response()->json([
            'data' => ClubVisualResource::collection($this->visuals->list($tenantId))->resolve(),
        ]);
    }

    public function store(StoreClubVisualRequest $request)
    {
        $tenantId = (int) $request->user()->tenant_id;
        $visual = $this->visuals->create($tenantId, $request->payload());

        return response()->json([
            'data' => (new ClubVisualResource($visual))->resolve(),
        ], 201);
    }

    public function update(UpdateClubVisualRequest $request, int $id)
    {
        $tenantId = (int) $request->user()->tenant_id;
        $visual = $this->visuals->update($tenantId, $id, $request->payload());

        return response()->json([
            'data' => (new ClubVisualResource($visual))->resolve(),
        ]);
    }

    public function toggle(Request $request, int $id)
    {
        $tenantId = (int) $request->user()->tenant_id;
        $visual = $this->visuals->toggle($tenantId, $id);

        return response()->json([
            'data' => (new ClubVisualResource($visual))->resolve(),
        ]);
    }

    public function destroy(Request $request, int $id)
    {
        $tenantId = (int) $request->user()->tenant_id;
        $this->visuals->delete($tenantId, $id);

        return response()->json([
            'ok' => true,
        ]);
    }

    public function uploadImage(Request $request)
    {
        $tenantId = (int) $request->user()->tenant_id;

        $request->validate([
            'file' => [
                'required',
                'file',
                'max:8192',
                'mimetypes:image/png,image/jpeg,image/webp,image/svg+xml',
            ],
        ]);

        $upload = $this->assets->storePublicAsset(
            $request->file('file'),
            'club_visuals/images/' . $tenantId,
            ['png', 'jpg', 'jpeg', 'webp', 'svg'],
            'Faqat PNG/JPG/WEBP/SVG fayl yuklash mumkin.',
        );

        return response()->json([
            'ok' => true,
            'url' => $this->normalizeUploadUrl($upload['url'], $request),
        ]);
    }

    public function uploadAudio(Request $request)
    {
        $tenantId = (int) $request->user()->tenant_id;

        $request->validate([
            'file' => [
                'required',
                'file',
                'max:25600',
                'mimetypes:audio/mpeg,audio/mp3,audio/wav,audio/x-wav,audio/mp4,audio/aac,audio/ogg,audio/webm,video/webm',
            ],
        ]);

        $upload = $this->assets->storePublicAsset(
            $request->file('file'),
            'club_visuals/audio/' . $tenantId,
            ['mp3', 'wav', 'm4a', 'aac', 'ogg', 'webm'],
            'Faqat MP3/WAV/M4A/AAC/OGG/WEBM audio yuklash mumkin.',
        );

        return response()->json([
            'ok' => true,
            'url' => $this->normalizeUploadUrl($upload['url'], $request),
        ]);
    }

    public function generateDraft(GenerateClubVisualDraftRequest $request)
    {
        $tenantId = (int) $request->user()->tenant_id;
        $draft = $this->aiDrafts->generate($tenantId, $request->payload());

        return response()->json([
            'data' => $draft,
        ]);
    }

    private function normalizeUploadUrl(string $url, Request $request): string
    {
        if (str_starts_with($url, 'http://') || str_starts_with($url, 'https://')) {
            return $url;
        }

        return rtrim($request->getSchemeAndHttpHost(), '/') . '/' . ltrim($url, '/');
    }
}
