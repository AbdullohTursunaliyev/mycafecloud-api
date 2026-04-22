<?php

namespace App\Http\Controllers\Api;

use App\Actions\Settings\UpdateTenantSettingsAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\UpdateSettingsRequest;
use App\Models\Setting;
use App\Services\SettingRegistry;
use App\Services\TenantAssetService;
use App\Services\TenantSettingService;
use Illuminate\Http\Request;

class SettingController extends Controller
{
    public function __construct(
        private readonly TenantSettingService $settings,
        private readonly SettingRegistry $registry,
        private readonly UpdateTenantSettingsAction $updateSettings,
        private readonly TenantAssetService $assets,
    ) {
    }

    public function index(Request $request)
    {
        $tenantId = (int) $request->user()->tenant_id;
        $baseUrl = $this->baseUrl($request);

        $settings = Setting::query()
            ->where('tenant_id', $tenantId)
            ->get()
            ->map(fn(Setting $setting) => [
                'key' => $setting->key,
                'value' => $this->registry->normalizeForResponse($setting->key, $setting->value, $baseUrl),
            ]);

        return response()->json(['data' => $settings]);
    }

    public function update(UpdateSettingsRequest $request)
    {
        $tenantId = (int) $request->user()->tenant_id;
        $this->updateSettings->execute($tenantId, $request->settings(), $this->baseUrl($request));

        return response()->json(['ok' => true]);
    }

    public function uploadPromoVideo(Request $request)
    {
        $tenantId = (int) $request->user()->tenant_id;

        $request->validate([
            'file' => [
                'required',
                'file',
                'max:204800',
                'mimetypes:video/mp4,video/webm,image/gif',
            ],
        ]);

        $upload = $this->assets->storePublicAsset(
            $request->file('file'),
            'promo_videos/' . $tenantId,
            ['mp4', 'webm', 'gif'],
            'Faqat MP4/WebM/GIF fayl yuklash mumkin.',
        );

        $url = $this->persistUploadedUrl($tenantId, 'promo_video_url', $upload['url'], $request);

        return response()->json([
            'ok' => true,
            'promo_video_url' => $url,
        ]);
    }

    public function uploadAgentInstaller(Request $request)
    {
        $tenantId = (int) $request->user()->tenant_id;

        $request->validate([
            'file' => [
                'required',
                'file',
                'max:512000',
                'mimes:exe,msi,zip',
            ],
        ]);

        $upload = $this->assets->storePublicAsset(
            $request->file('file'),
            'agent_installers/' . $tenantId,
            ['exe', 'msi', 'zip'],
            'Faqat EXE/MSI/ZIP fayl yuklash mumkin.',
            withHash: true,
        );

        $url = $this->persistUploadedUrl($tenantId, 'deploy_agent_download_url', $upload['url'], $request);
        if ($upload['sha256'] !== null) {
            $this->settings->set($tenantId, 'deploy_agent_sha256', $upload['sha256']);
        }

        return response()->json([
            'ok' => true,
            'deploy_agent_download_url' => $url,
            'deploy_agent_sha256' => $upload['sha256'],
            'url' => $url,
        ]);
    }

    public function uploadClientInstaller(Request $request)
    {
        $tenantId = (int) $request->user()->tenant_id;

        $request->validate([
            'file' => [
                'required',
                'file',
                'max:512000',
                'mimes:exe,msi,zip',
            ],
        ]);

        $upload = $this->assets->storePublicAsset(
            $request->file('file'),
            'client_installers/' . $tenantId,
            ['exe', 'msi', 'zip'],
            'Faqat EXE/MSI/ZIP fayl yuklash mumkin.',
        );

        $url = $this->persistUploadedUrl($tenantId, 'deploy_client_download_url', $upload['url'], $request);

        return response()->json([
            'ok' => true,
            'deploy_client_download_url' => $url,
            'url' => $url,
        ]);
    }

    public function uploadShellInstaller(Request $request)
    {
        $tenantId = (int) $request->user()->tenant_id;

        $request->validate([
            'file' => [
                'required',
                'file',
                'max:512000',
                'mimes:exe,msi,zip',
            ],
        ]);

        $upload = $this->assets->storePublicAsset(
            $request->file('file'),
            'shell_installers/' . $tenantId,
            ['exe', 'msi', 'zip'],
            'Faqat EXE/MSI/ZIP fayl yuklash mumkin.',
            withHash: true,
        );

        $url = $this->persistUploadedUrl($tenantId, 'deploy_shell_download_url', $upload['url'], $request);
        if ($upload['sha256'] !== null) {
            $this->settings->set($tenantId, 'deploy_shell_sha256', $upload['sha256']);
        }

        return response()->json([
            'ok' => true,
            'deploy_shell_download_url' => $url,
            'deploy_shell_sha256' => $upload['sha256'],
            'url' => $url,
        ]);
    }

    private function persistUploadedUrl(int $tenantId, string $key, string $url, Request $request): string
    {
        return $this->assets->persistNormalizedUrl($tenantId, $key, $url, $this->baseUrl($request));
    }

    private function baseUrl(Request $request): string
    {
        return rtrim($request->getSchemeAndHttpHost(), '/');
    }
}
