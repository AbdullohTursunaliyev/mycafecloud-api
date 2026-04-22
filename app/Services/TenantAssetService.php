<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class TenantAssetService
{
    public function __construct(
        private readonly TenantSettingService $settings,
        private readonly SettingRegistry $registry,
    ) {
    }

    public function storePublicAsset(
        UploadedFile $file,
        string $directory,
        array $extensions,
        string $message,
        bool $withHash = false,
    ): array {
        $extension = strtolower($file->getClientOriginalExtension() ?: '');
        if (!in_array($extension, $extensions, true)) {
            throw ValidationException::withMessages([
                'file' => $message,
            ]);
        }

        $path = $file->storeAs(
            $directory,
            Str::uuid()->toString() . '.' . $extension,
            'public'
        );

        return [
            'path' => $path,
            'url' => (string) Storage::disk('public')->url($path),
            'sha256' => $withHash ? hash_file('sha256', $file->getRealPath()) : null,
        ];
    }

    public function persistNormalizedUrl(int $tenantId, string $key, string $url, string $baseUrl): string
    {
        $normalized = (string) $this->registry->normalizeForStorage($key, $url, $baseUrl);
        $this->settings->set($tenantId, $key, $normalized);

        return $normalized;
    }
}
