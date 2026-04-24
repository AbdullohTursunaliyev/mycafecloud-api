<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\StoreShellBannerRequest;
use App\Http\Requests\Api\UpdateShellBannerRequest;
use App\Http\Resources\Admin\ShellBannerResource;
use App\Services\ShellBannerCatalogService;
use App\Services\TenantAssetService;
use Illuminate\Http\Request;

class ShellBannerController extends Controller
{
    public function __construct(
        private readonly ShellBannerCatalogService $banners,
        private readonly TenantAssetService $assets,
    ) {
    }

    public function index(Request $request)
    {
        $tenantId = (int) $request->user()->tenant_id;

        return response()->json([
            'data' => ShellBannerResource::collection($this->banners->list($tenantId))->resolve(),
        ]);
    }

    public function store(StoreShellBannerRequest $request)
    {
        $tenantId = (int) $request->user()->tenant_id;
        $banner = $this->banners->create($tenantId, $request->payload());

        return response()->json([
            'data' => (new ShellBannerResource($banner))->resolve(),
        ], 201);
    }

    public function update(UpdateShellBannerRequest $request, int $id)
    {
        $tenantId = (int) $request->user()->tenant_id;
        $banner = $this->banners->update($tenantId, $id, $request->payload());

        return response()->json([
            'data' => (new ShellBannerResource($banner))->resolve(),
        ]);
    }

    public function toggle(Request $request, int $id)
    {
        $tenantId = (int) $request->user()->tenant_id;
        $banner = $this->banners->toggle($tenantId, $id);

        return response()->json([
            'data' => (new ShellBannerResource($banner))->resolve(),
        ]);
    }

    public function destroy(Request $request, int $id)
    {
        $tenantId = (int) $request->user()->tenant_id;
        $this->banners->delete($tenantId, $id);

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
            'shell_banners/images/' . $tenantId,
            ['png', 'jpg', 'jpeg', 'webp', 'svg'],
            'Faqat PNG/JPG/WEBP/SVG fayl yuklash mumkin.',
        );

        return response()->json([
            'ok' => true,
            'url' => $this->normalizeUploadUrl($upload['url'], $request),
        ]);
    }

    public function uploadLogo(Request $request)
    {
        $tenantId = (int) $request->user()->tenant_id;

        $request->validate([
            'file' => [
                'required',
                'file',
                'max:4096',
                'mimetypes:image/png,image/jpeg,image/webp,image/svg+xml',
            ],
        ]);

        $upload = $this->assets->storePublicAsset(
            $request->file('file'),
            'shell_banners/logos/' . $tenantId,
            ['png', 'jpg', 'jpeg', 'webp', 'svg'],
            'Faqat PNG/JPG/WEBP/SVG logo yuklash mumkin.',
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
            'shell_banners/audio/' . $tenantId,
            ['mp3', 'wav', 'm4a', 'aac', 'ogg', 'webm'],
            'Faqat MP3/WAV/M4A/AAC/OGG/WEBM audio yuklash mumkin.',
        );

        return response()->json([
            'ok' => true,
            'url' => $this->normalizeUploadUrl($upload['url'], $request),
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
