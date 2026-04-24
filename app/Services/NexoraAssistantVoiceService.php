<?php

namespace App\Services;

use Illuminate\Http\Client\Response;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class NexoraAssistantVoiceService
{
    public function synthesize(string $text, string $locale = 'uz'): array
    {
        $this->ensureConfigured();

        $response = $this->openAiAudio()
            ->post('audio/speech', [
                'model' => (string) config('services.openai.nexora_tts_model', 'gpt-4o-mini-tts'),
                'voice' => $this->voiceForLocale($locale),
                'input' => $text,
                'instructions' => $this->instructionsForLocale($locale),
                'response_format' => 'mp3',
            ]);

        if ($response->failed()) {
            Log::warning('nexora assistant tts failed', [
                'status' => $response->status(),
                'body' => $response->json(),
                'locale' => $locale,
            ]);

            throw ValidationException::withMessages([
                'openai' => $this->openAiErrorMessage($response),
            ]);
        }

        return [
            'content' => $response->body(),
            'content_type' => $response->header('Content-Type', 'audio/mpeg'),
            'voice' => $this->voiceForLocale($locale),
        ];
    }

    public function transcribe(UploadedFile $audio, string $locale = 'uz'): array
    {
        $this->ensureConfigured();

        $binary = file_get_contents($audio->getRealPath());
        if ($binary === false || $binary === '') {
            throw ValidationException::withMessages([
                'audio' => 'Audio faylni o‘qib bo‘lmadi.',
            ]);
        }

        $filename = $audio->getClientOriginalName() ?: ('nexora-input.' . ($audio->extension() ?: 'm4a'));

        $response = $this->openAiJson()
            ->attach('file', $binary, $filename)
            ->post('audio/transcriptions', [
                'model' => (string) config('services.openai.transcription_model', 'gpt-4o-mini-transcribe'),
                'response_format' => 'text',
                'language' => $locale,
                'prompt' => $this->transcriptionPromptForLocale($locale),
            ]);

        if ($response->failed()) {
            Log::warning('nexora assistant transcription failed', [
                'status' => $response->status(),
                'body' => $response->json(),
                'locale' => $locale,
                'filename' => $filename,
            ]);

            throw ValidationException::withMessages([
                'audio' => $this->openAiErrorMessage($response),
            ]);
        }

        return [
            'text' => trim((string) $response->body()),
            'locale' => $locale,
        ];
    }

    private function ensureConfigured(): void
    {
        $apiKey = trim((string) config('services.openai.api_key'));
        if ($apiKey === '') {
            throw ValidationException::withMessages([
                'openai' => 'OPENAI_API_KEY sozlanmagan.',
            ]);
        }
    }

    private function openAiAudio()
    {
        return Http::withToken((string) config('services.openai.api_key'))
            ->baseUrl((string) config('services.openai.base_url', 'https://api.openai.com/v1'))
            ->timeout(60)
            ->withHeaders([
                'Accept' => 'audio/mpeg',
            ]);
    }

    private function openAiJson()
    {
        return Http::withToken((string) config('services.openai.api_key'))
            ->acceptJson()
            ->baseUrl((string) config('services.openai.base_url', 'https://api.openai.com/v1'))
            ->timeout(60);
    }

    private function voiceForLocale(string $locale): string
    {
        return match ($locale) {
            'ru' => (string) config('services.openai.nexora_tts_voice_ru', config('services.openai.nexora_tts_voice', 'marin')),
            'en' => (string) config('services.openai.nexora_tts_voice_en', config('services.openai.nexora_tts_voice', 'marin')),
            default => (string) config('services.openai.nexora_tts_voice_uz', config('services.openai.nexora_tts_voice', 'marin')),
        };
    }

    private function instructionsForLocale(string $locale): string
    {
        return match ($locale) {
            'ru' => 'Speak naturally in Russian with a warm, confident, helpful assistant tone. Keep pacing smooth and human-like.',
            'en' => 'Speak naturally in English with a warm, confident, helpful assistant tone. Keep pacing smooth and human-like.',
            default => 'Speak naturally in Uzbek with a warm, confident, helpful assistant tone. Keep pacing smooth and human-like.',
        };
    }

    private function transcriptionPromptForLocale(string $locale): string
    {
        return match ($locale) {
            'ru' => 'This is a Russian-language command for Nexora AI to manage a gaming club. Prefer words related to PCs, zones, revenue, lock, reboot, and offline machines.',
            'en' => 'This is an English command for Nexora AI to manage a gaming club. Prefer words related to PCs, zones, revenue, lock, reboot, and offline machines.',
            default => 'This is an Uzbek command for Nexora AI to manage a gaming club. Prefer Uzbek gaming-club words such as kompyuter, zona, tushum, yoniq, o‘chir, lock, reboot, offline, va zal.',
        };
    }

    private function openAiErrorMessage(Response $response): string
    {
        $json = $response->json();
        $message = $json['error']['message'] ?? null;

        return is_string($message) && $message !== ''
            ? $message
            : 'OpenAI audio so‘rovida xatolik yuz berdi.';
    }
}
