<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use App\Service\SettingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class SettingController extends Controller
{
    public function index(Request $request)
    {
        $tenantId = $request->user()->tenant_id;

        $base = rtrim($request->getSchemeAndHttpHost(), '/');
        $settings = Setting::where('tenant_id',$tenantId)->get()
            ->map(function ($s) use ($tenantId, $base) {
                $value = $s->value;
                if ($s->key === 'promo_video_url') {
                    $fixed = $this->normalizePromoUrl($value, $base);
                    if (is_string($fixed) && $fixed !== $value) {
                        SettingService::set($tenantId, 'promo_video_url', $fixed);
                        $value = $fixed;
                    }
                }
                return [
                    'key'=>$s->key,
                    'value'=>$value
                ];
            });

        return response()->json(['data'=>$settings]);
    }

    public function update(Request $request)
    {
        $tenantId = $request->user()->tenant_id;

        $data = $request->validate([
            'settings' => ['required','array'],
        ]);
        $settings = $data['settings'];

        if (array_key_exists('club_name', $settings)) {
            Validator::make(['club_name' => $settings['club_name']], [
                'club_name' => ['nullable', 'string', 'max:120'],
            ])->validate();
        }

        if (array_key_exists('club_logo', $settings)) {
            Validator::make(['club_logo' => $settings['club_logo']], [
                'club_logo' => ['nullable', 'string', 'max:2000000'],
            ])->validate();
        }

        if (array_key_exists('promo_video_url', $settings)) {
            Validator::make(['promo_video_url' => $settings['promo_video_url']], [
                'promo_video_url' => ['nullable', 'string', 'max:1000', 'url'],
            ])->validate();
        }

        if (array_key_exists('club_location', $settings)) {
            Validator::make(['club_location' => $settings['club_location']], [
                'club_location' => ['nullable', 'array'],
                'club_location.lat' => ['nullable', 'numeric', 'between:-90,90'],
                'club_location.lng' => ['nullable', 'numeric', 'between:-180,180'],
                'club_location.address' => ['nullable', 'string', 'max:255'],
            ])->validate();
        }

        if (array_key_exists('telegram_user', $settings)) {
            Validator::make(['telegram_user' => $settings['telegram_user']], [
                'telegram_user' => ['nullable', 'string', 'max:120'],
            ])->validate();
        }

        if (array_key_exists('telegram_chat_id', $settings)) {
            Validator::make(['telegram_chat_id' => $settings['telegram_chat_id']], [
                'telegram_chat_id' => ['nullable', 'string', 'max:120'],
            ])->validate();
        }

        if (array_key_exists('telegram_bot_token', $settings)) {
            Validator::make(['telegram_bot_token' => $settings['telegram_bot_token']], [
                'telegram_bot_token' => ['nullable', 'string', 'max:255'],
            ])->validate();
        }

        if (array_key_exists('telegram_shift_notifications', $settings)) {
            Validator::make(['telegram_shift_notifications' => $settings['telegram_shift_notifications']], [
                'telegram_shift_notifications' => ['nullable', 'boolean'],
            ])->validate();
        }

        if (array_key_exists('deploy_agent_download_url', $settings)) {
            Validator::make(['deploy_agent_download_url' => $settings['deploy_agent_download_url']], [
                'deploy_agent_download_url' => ['nullable', 'string', 'max:500', 'url'],
            ])->validate();
        }

        if (array_key_exists('deploy_agent_install_args', $settings)) {
            Validator::make(['deploy_agent_install_args' => $settings['deploy_agent_install_args']], [
                'deploy_agent_install_args' => ['nullable', 'string', 'max:1000'],
            ])->validate();
        }

        if (array_key_exists('deploy_agent_sha256', $settings)) {
            Validator::make(['deploy_agent_sha256' => $settings['deploy_agent_sha256']], [
                'deploy_agent_sha256' => ['nullable', 'string', 'max:128'],
            ])->validate();
        }

        if (array_key_exists('deploy_shell_sha256', $settings)) {
            Validator::make(['deploy_shell_sha256' => $settings['deploy_shell_sha256']], [
                'deploy_shell_sha256' => ['nullable', 'string', 'max:128'],
            ])->validate();
        }

        if (array_key_exists('deploy_client_download_url', $settings)) {
            Validator::make(['deploy_client_download_url' => $settings['deploy_client_download_url']], [
                'deploy_client_download_url' => ['nullable', 'string', 'max:500', 'url'],
            ])->validate();
        }

        if (array_key_exists('deploy_client_install_args', $settings)) {
            Validator::make(['deploy_client_install_args' => $settings['deploy_client_install_args']], [
                'deploy_client_install_args' => ['nullable', 'string', 'max:1000'],
            ])->validate();
        }

        if (array_key_exists('deploy_shell_download_url', $settings)) {
            Validator::make(['deploy_shell_download_url' => $settings['deploy_shell_download_url']], [
                'deploy_shell_download_url' => ['nullable', 'string', 'max:500', 'url'],
            ])->validate();
        }

        if (array_key_exists('deploy_shell_install_args', $settings)) {
            Validator::make(['deploy_shell_install_args' => $settings['deploy_shell_install_args']], [
                'deploy_shell_install_args' => ['nullable', 'string', 'max:1000'],
            ])->validate();
        }

        if (array_key_exists('shell_autostart_enabled', $settings)) {
            Validator::make(['shell_autostart_enabled' => $settings['shell_autostart_enabled']], [
                'shell_autostart_enabled' => ['nullable', 'boolean'],
            ])->validate();
        }

        if (array_key_exists('shell_autostart_path', $settings)) {
            Validator::make(['shell_autostart_path' => $settings['shell_autostart_path']], [
                'shell_autostart_path' => ['nullable', 'string', 'max:500'],
            ])->validate();
        }

        if (array_key_exists('shell_autostart_args', $settings)) {
            Validator::make(['shell_autostart_args' => $settings['shell_autostart_args']], [
                'shell_autostart_args' => ['nullable', 'string', 'max:500'],
            ])->validate();
        }

        if (array_key_exists('shell_autostart_scope', $settings)) {
            Validator::make(['shell_autostart_scope' => $settings['shell_autostart_scope']], [
                'shell_autostart_scope' => ['nullable', 'in:user,machine'],
            ])->validate();
        }

        if (array_key_exists('shell_replace_explorer_enabled', $settings)) {
            Validator::make(['shell_replace_explorer_enabled' => $settings['shell_replace_explorer_enabled']], [
                'shell_replace_explorer_enabled' => ['nullable', 'boolean'],
            ])->validate();
        }

        if (array_key_exists('shell_replace_explorer_path', $settings)) {
            Validator::make(['shell_replace_explorer_path' => $settings['shell_replace_explorer_path']], [
                'shell_replace_explorer_path' => ['nullable', 'string', 'max:500'],
            ])->validate();
        }

        if (array_key_exists('shell_replace_explorer_args', $settings)) {
            Validator::make(['shell_replace_explorer_args' => $settings['shell_replace_explorer_args']], [
                'shell_replace_explorer_args' => ['nullable', 'string', 'max:500'],
            ])->validate();
        }

        if (array_key_exists('auto_shift_enabled', $settings)) {
            Validator::make(['auto_shift_enabled' => $settings['auto_shift_enabled']], [
                'auto_shift_enabled' => ['nullable', 'boolean'],
            ])->validate();
        }

        if (array_key_exists('auto_shift_opening_cash', $settings)) {
            Validator::make(['auto_shift_opening_cash' => $settings['auto_shift_opening_cash']], [
                'auto_shift_opening_cash' => ['nullable', 'integer', 'min:0'],
            ])->validate();
        }

        if (array_key_exists('auto_shift_slots', $settings)) {
            Validator::make(['auto_shift_slots' => $settings['auto_shift_slots']], [
                'auto_shift_slots' => ['nullable', 'array', 'max:6'],
                'auto_shift_slots.*.start' => ['required', 'date_format:H:i'],
                'auto_shift_slots.*.end' => ['required', 'date_format:H:i'],
                'auto_shift_slots.*.label' => ['nullable', 'string', 'max:80'],
            ])->validate();
        }

        $currentAutoShiftEnabled = $this->asBool(SettingService::get($tenantId, 'auto_shift_enabled', false));
        $nextAutoShiftEnabled = array_key_exists('auto_shift_enabled', $settings)
            ? $this->asBool($settings['auto_shift_enabled'])
            : $currentAutoShiftEnabled;

        $currentSlots = SettingService::get($tenantId, 'auto_shift_slots', []);
        if (!is_array($currentSlots)) {
            $currentSlots = [];
        }

        $incomingSlots = [];
        if (array_key_exists('auto_shift_slots', $settings) && is_array($settings['auto_shift_slots'])) {
            $incomingSlots = $settings['auto_shift_slots'];
        }

        if ($nextAutoShiftEnabled) {
            // Auto shift yoqilgan holda bo'sh slots yuborilsa va eski slots mavjud bo'lsa, eskisini saqlab qolamiz.
            if (array_key_exists('auto_shift_slots', $settings) && count($incomingSlots) === 0 && count($currentSlots) > 0) {
                unset($settings['auto_shift_slots']);
            }

            $effectiveSlots = array_key_exists('auto_shift_slots', $settings)
                ? (is_array($settings['auto_shift_slots']) ? $settings['auto_shift_slots'] : [])
                : $currentSlots;

            if (count($effectiveSlots) < 1) {
                throw ValidationException::withMessages([
                    'auto_shift_slots' => 'The auto shift slots field must have at least 1 items.',
                ]);
            }
        }

        foreach ($settings as $key => $value) {
            SettingService::set($tenantId, $key, $value);
        }

        return response()->json(['ok'=>true]);
    }

    // POST /api/settings/promo-video (multipart: file)
    public function uploadPromoVideo(Request $request)
    {
        $tenantId = $request->user()->tenant_id;

        $request->validate([
            'file' => [
                'required',
                'file',
                'max:204800', // 200MB
                'mimetypes:video/mp4,video/webm,image/gif',
            ],
        ]);

        $file = $request->file('file');
        $ext = strtolower($file->getClientOriginalExtension() ?: '');
        if (!in_array($ext, ['mp4', 'webm', 'gif'], true)) {
            throw ValidationException::withMessages([
                'file' => 'Faqat MP4/WebM/GIF fayl yuklash mumkin.',
            ]);
        }

        $path = $file->storeAs(
            'promo_videos/' . $tenantId,
            Str::uuid()->toString() . '.' . $ext,
            'public'
        );

        $relative = Storage::disk('public')->url($path);
        if (Str::startsWith($relative, ['http://', 'https://'])) {
            $url = $relative;
        } else {
            $base = rtrim($request->getSchemeAndHttpHost(), '/');
            $url = $base . (Str::startsWith($relative, '/') ? $relative : ('/' . ltrim($relative, '/')));
        }
        SettingService::set($tenantId, 'promo_video_url', $url);

        return response()->json([
            'ok' => true,
            'promo_video_url' => $url,
        ]);
    }

    public function uploadAgentInstaller(Request $request)
    {
        $tenantId = $request->user()->tenant_id;

        $request->validate([
            'file' => [
                'required',
                'file',
                'max:512000', // 500MB
                'mimes:exe,msi,zip',
            ],
        ]);

        $file = $request->file('file');
        $ext = strtolower($file->getClientOriginalExtension() ?: '');
        if (!in_array($ext, ['exe', 'msi', 'zip'], true)) {
            throw ValidationException::withMessages([
                'file' => 'Faqat EXE/MSI/ZIP fayl yuklash mumkin.',
            ]);
        }

        $hash = hash_file('sha256', $file->getRealPath());

        $path = $file->storeAs(
            'agent_installers/' . $tenantId,
            Str::uuid()->toString() . '.' . $ext,
            'public'
        );

        $relative = Storage::disk('public')->url($path);
        $url = $this->normalizePromoUrl($relative, rtrim($request->getSchemeAndHttpHost(), '/'));

        SettingService::set($tenantId, 'deploy_agent_download_url', $url);
        if (!empty($hash)) {
            SettingService::set($tenantId, 'deploy_agent_sha256', $hash);
        }

        return response()->json([
            'ok' => true,
            'deploy_agent_download_url' => $url,
            'deploy_agent_sha256' => $hash,
            'url' => $url,
        ]);
    }

    public function uploadClientInstaller(Request $request)
    {
        $tenantId = $request->user()->tenant_id;

        $request->validate([
            'file' => [
                'required',
                'file',
                'max:512000', // 500MB
                'mimes:exe,msi,zip',
            ],
        ]);

        $file = $request->file('file');
        $ext = strtolower($file->getClientOriginalExtension() ?: '');
        if (!in_array($ext, ['exe', 'msi', 'zip'], true)) {
            throw ValidationException::withMessages([
                'file' => 'Faqat EXE/MSI/ZIP fayl yuklash mumkin.',
            ]);
        }

        $path = $file->storeAs(
            'client_installers/' . $tenantId,
            Str::uuid()->toString() . '.' . $ext,
            'public'
        );

        $relative = Storage::disk('public')->url($path);
        $url = $this->normalizePromoUrl($relative, rtrim($request->getSchemeAndHttpHost(), '/'));

        SettingService::set($tenantId, 'deploy_client_download_url', $url);

        return response()->json([
            'ok' => true,
            'deploy_client_download_url' => $url,
            'url' => $url,
        ]);
    }

    public function uploadShellInstaller(Request $request)
    {
        $tenantId = $request->user()->tenant_id;

        $request->validate([
            'file' => [
                'required',
                'file',
                'max:512000', // 500MB
                'mimes:exe,msi,zip',
            ],
        ]);

        $file = $request->file('file');
        $ext = strtolower($file->getClientOriginalExtension() ?: '');
        if (!in_array($ext, ['exe', 'msi', 'zip'], true)) {
            throw ValidationException::withMessages([
                'file' => 'Faqat EXE/MSI/ZIP fayl yuklash mumkin.',
            ]);
        }

        $hash = hash_file('sha256', $file->getRealPath());

        $path = $file->storeAs(
            'shell_installers/' . $tenantId,
            Str::uuid()->toString() . '.' . $ext,
            'public'
        );

        $relative = Storage::disk('public')->url($path);
        $url = $this->normalizePromoUrl($relative, rtrim($request->getSchemeAndHttpHost(), '/'));

        SettingService::set($tenantId, 'deploy_shell_download_url', $url);
        if (!empty($hash)) {
            SettingService::set($tenantId, 'deploy_shell_sha256', $hash);
        }

        return response()->json([
            'ok' => true,
            'deploy_shell_download_url' => $url,
            'deploy_shell_sha256' => $hash,
            'url' => $url,
        ]);
    }

    private function normalizePromoUrl(mixed $value, string $base): mixed
    {
        if (!is_string($value)) {
            return $value;
        }

        $url = trim($value);
        if ($url === '') {
            return $value;
        }

        if (str_starts_with($url, '/')) {
            return $base . $url;
        }

        $fixed = preg_replace('#^https?://(localhost|127\\.0\\.0\\.1)(:\\d+)?#i', $base, $url);
        return $fixed ?: $url;
    }

    private function asBool(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        $v = strtolower(trim((string)$value));
        return !in_array($v, ['', '0', 'false', 'off', 'no', 'null'], true);
    }
}
