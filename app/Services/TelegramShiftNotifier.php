<?php

namespace App\Services;

use App\Models\Tenant;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class TelegramShiftNotifier
{
    public function __construct(
        private readonly TenantSettingService $settings,
    ) {
    }

    public function shiftOpened(int $tenantId, array $payload): void
    {
        $checklist = is_array($payload['checklist'] ?? null) ? $payload['checklist'] : [];
        $lines = [
            'SMENA OCHILDI',
            '------------------------------',
            'Klub: ' . $this->clubName($tenantId),
            'Smena ID: #' . (int) ($payload['shift_id'] ?? 0),
            'Operator: ' . $this->str($payload['opened_by'] ?? '-'),
            'Vaqt: ' . $this->str($payload['opened_at'] ?? '-'),
            'Boshlangich naqd: ' . $this->money($payload['opening_cash'] ?? 0) . ' UZS',
        ];

        if ($checklist !== []) {
            $lines[] = 'Checklist: kassa=' . $this->yesNo($checklist['cash_ok'] ?? false)
                . ', internet=' . $this->yesNo($checklist['internet_ok'] ?? false)
                . ', zal=' . $this->yesNo($checklist['hall_cleaned'] ?? false);
        }

        $text = implode("\n", $lines);

        $this->send($tenantId, $text);

        $cashPhotoPath = trim((string) ($checklist['cash_photo_path'] ?? ''));
        if ($cashPhotoPath !== '') {
            $this->sendPhoto($tenantId, $cashPhotoPath, 'Checklist: kassa rasmi');
        }

        $hallPhotoPath = trim((string) ($checklist['hall_photo_path'] ?? ''));
        if ($hallPhotoPath !== '') {
            $this->sendPhoto($tenantId, $hallPhotoPath, 'Checklist: zal rasmi');
        }
    }

    public function shiftClosed(int $tenantId, array $payload): void
    {
        $text = implode("\n", [
            'SMENA YOPILDI',
            '------------------------------',
            'Klub: ' . $this->clubName($tenantId),
            'Smena ID: #' . (int) ($payload['shift_id'] ?? 0),
            'Ochgan operator: ' . $this->str($payload['opened_by'] ?? '-'),
            'Yopgan operator: ' . $this->str($payload['closed_by'] ?? '-'),
            'Ochilgan vaqt: ' . $this->str($payload['opened_at'] ?? '-'),
            'Yopilgan vaqt: ' . $this->str($payload['closed_at'] ?? '-'),
            '------------------------------',
            'Boshlangich naqd: ' . $this->money($payload['opening_cash'] ?? 0) . ' UZS',
            'Tushum (naqd): ' . $this->money($payload['topups_cash_total'] ?? 0) . ' UZS',
            'Tushum (karta): ' . $this->money($payload['topups_card_total'] ?? 0) . ' UZS',
            'Bonus: ' . $this->money($payload['topups_bonus_total'] ?? 0) . ' UZS',
            'Amallar soni: ' . (int) ($payload['topups_ops_count'] ?? 0),
            'Qaytarish (jami): ' . $this->money($payload['returns_total'] ?? 0) . ' UZS',
            'Qaytarish (naqd): ' . $this->money($payload['returns_cash_total'] ?? 0) . ' UZS',
            'Xarajatlar: ' . $this->money($payload['expenses_total'] ?? 0) . ' UZS',
            '------------------------------',
            'Kutilgan naqd: ' . $this->money($payload['expected_cash'] ?? 0) . ' UZS',
            'Yopish naqd: ' . $this->money($payload['closing_cash'] ?? 0) . ' UZS',
            'Ortiqcha: ' . $this->money($payload['diff_overage'] ?? 0) . ' UZS',
            'Kamomad: ' . $this->money($payload['diff_shortage'] ?? 0) . ' UZS',
        ]);

        $this->send($tenantId, $text);
    }

    private function send(int $tenantId, string $text): void
    {
        if (!$this->notificationsEnabled($tenantId)) {
            return;
        }

        $botToken = $this->resolveBotToken($tenantId);
        if ($botToken === '') {
            return;
        }

        $chatId = $this->targetChatId($tenantId, $botToken);
        if ($chatId === null) {
            return;
        }

        try {
            $response = Http::asForm()
                ->timeout(7)
                ->post("https://api.telegram.org/bot{$botToken}/sendMessage", [
                    'chat_id' => $chatId,
                    'text' => $text,
                    'disable_web_page_preview' => true,
                ]);

            $okFlag = (bool) ($response->json('ok') ?? true);
            if (!$response->successful() || !$okFlag) {
                Log::warning('Telegram shift notification rejected', [
                    'tenant_id' => $tenantId,
                    'chat_id' => $chatId,
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);
            }
        } catch (\Throwable $e) {
            Log::warning('Telegram shift notification failed', [
                'tenant_id' => $tenantId,
                'chat_id' => $chatId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function sendPhoto(int $tenantId, string $relativePath, string $caption = ''): void
    {
        if (!$this->notificationsEnabled($tenantId)) {
            return;
        }

        $botToken = $this->resolveBotToken($tenantId);
        if ($botToken === '') {
            return;
        }

        $chatId = $this->targetChatId($tenantId, $botToken);
        if ($chatId === null) {
            return;
        }

        try {
            $disk = Storage::disk('public');
            if (!$disk->exists($relativePath)) {
                return;
            }

            $payload = ['chat_id' => $chatId];
            if (trim($caption) !== '') {
                $payload['caption'] = $caption;
            }

            $response = Http::timeout(10)
                ->attach('photo', $disk->get($relativePath), basename($relativePath))
                ->post("https://api.telegram.org/bot{$botToken}/sendPhoto", $payload);

            $okFlag = (bool) ($response->json('ok') ?? true);
            if (!$response->successful() || !$okFlag) {
                Log::warning('Telegram shift photo notification rejected', [
                    'tenant_id' => $tenantId,
                    'path' => $relativePath,
                    'chat_id' => $chatId,
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);
            }
        } catch (\Throwable $e) {
            Log::warning('Telegram shift photo notification failed', [
                'tenant_id' => $tenantId,
                'path' => $relativePath,
                'chat_id' => $chatId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function resolveBotToken(int $tenantId): string
    {
        $tenantToken = trim((string) $this->settings->get($tenantId, 'telegram_bot_token', ''));
        if ($tenantToken !== '') {
            return $tenantToken;
        }

        return trim((string) config('services.telegram.bot_token', ''));
    }

    private function notificationsEnabled(int $tenantId): bool
    {
        $raw = $this->settings->get($tenantId, 'telegram_shift_notifications', true);
        if (is_bool($raw)) {
            return $raw;
        }

        $value = strtolower(trim((string) $raw));

        return !in_array($value, ['0', 'false', 'off', 'no'], true);
    }

    private function targetChatId(int $tenantId, string $botToken): ?string
    {
        $chatId = trim((string) $this->settings->get($tenantId, 'telegram_chat_id', ''));
        $user = trim((string) $this->settings->get($tenantId, 'telegram_user', ''));

        if ($chatId !== '') {
            if (!preg_match('/^-?\d+$/', $chatId) && !str_starts_with($chatId, '@')) {
                $chatId = '@' . $chatId;
            }

            return $chatId;
        }

        if ($user === '') {
            return null;
        }

        $username = ltrim($user, '@');
        if ($username === '') {
            return null;
        }

        $resolved = $this->resolveChatIdByUsername($tenantId, $botToken, $username);
        if ($resolved !== null) {
            $this->settings->set($tenantId, 'telegram_chat_id', $resolved);

            return $resolved;
        }

        Log::warning('Telegram username could not be resolved to chat id', [
            'tenant_id' => $tenantId,
            'telegram_user' => $user,
        ]);

        return null;
    }

    private function resolveChatIdByUsername(int $tenantId, string $botToken, string $username): ?string
    {
        try {
            $response = Http::timeout(7)
                ->get("https://api.telegram.org/bot{$botToken}/getUpdates", [
                    'limit' => 100,
                ]);

            if (!$response->successful() || !(bool) ($response->json('ok') ?? false)) {
                Log::warning('Telegram getUpdates failed for username resolve', [
                    'tenant_id' => $tenantId,
                    'username' => $username,
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);

                return null;
            }

            $result = $response->json('result');
            if (!is_array($result)) {
                return null;
            }

            $target = strtolower($username);
            foreach ($result as $update) {
                if (!is_array($update)) {
                    continue;
                }

                $candidate = $this->extractChatIdFromUpdate($update, $target);
                if ($candidate !== null) {
                    return $candidate;
                }
            }
        } catch (\Throwable $e) {
            Log::warning('Telegram username resolve exception', [
                'tenant_id' => $tenantId,
                'username' => $username,
                'error' => $e->getMessage(),
            ]);
        }

        return null;
    }

    private function extractChatIdFromUpdate(array $update, string $targetUsername): ?string
    {
        $containers = [];
        if (isset($update['message']) && is_array($update['message'])) {
            $containers[] = $update['message'];
        }
        if (isset($update['edited_message']) && is_array($update['edited_message'])) {
            $containers[] = $update['edited_message'];
        }
        if (isset($update['callback_query']) && is_array($update['callback_query'])) {
            $cb = $update['callback_query'];
            if (isset($cb['message']) && is_array($cb['message'])) {
                $containers[] = $cb['message'];
            }
            if (isset($cb['from']) && is_array($cb['from'])) {
                $containers[] = ['from' => $cb['from'], 'chat' => ['id' => $cb['from']['id'] ?? null]];
            }
        }

        foreach ($containers as $payload) {
            $fromUsername = strtolower((string) ($payload['from']['username'] ?? ''));
            $chatUsername = strtolower((string) ($payload['chat']['username'] ?? ''));
            $chatId = $payload['chat']['id'] ?? $payload['from']['id'] ?? null;

            if ($chatId === null) {
                continue;
            }

            if ($fromUsername === $targetUsername || $chatUsername === $targetUsername) {
                return (string) $chatId;
            }
        }

        return null;
    }

    private function clubName(int $tenantId): string
    {
        $clubName = trim((string) $this->settings->get($tenantId, 'club_name', ''));
        if ($clubName !== '') {
            return $clubName;
        }

        return (string) (Tenant::query()->whereKey($tenantId)->value('name') ?? ('Tenant #' . $tenantId));
    }

    private function str(mixed $value): string
    {
        return trim((string) $value) !== '' ? (string) $value : '-';
    }

    private function money(mixed $value): string
    {
        return number_format((float) $value, 0, '.', ' ');
    }

    private function yesNo(mixed $value): string
    {
        return filter_var($value, FILTER_VALIDATE_BOOLEAN) ? 'ha' : 'yoq';
    }
}
