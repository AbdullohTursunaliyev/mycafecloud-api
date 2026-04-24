<?php

namespace App\Services;

use App\Models\Tenant;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class ClubVisualAiDraftService
{
    public function __construct(
        private readonly TenantSettingService $settings,
    ) {
    }

    public function generate(int $tenantId, array $payload): array
    {
        $apiKey = trim((string) config('services.openai.api_key'));
        if ($apiKey === '') {
            throw ValidationException::withMessages([
                'openai' => 'OPENAI_API_KEY sozlanmagan.',
            ]);
        }

        $tenant = Tenant::query()->find($tenantId);
        $clubName = trim((string) $this->settings->get($tenantId, 'club_name', $tenant?->name ?? 'Club'));
        $audioUrl = $payload['audio_url'] ?? null;
        $transcript = $audioUrl ? $this->transcribeAudio($audioUrl) : null;

        $draft = $this->requestDraft($clubName, [
            'prompt_text' => $payload['prompt_text'] ?? null,
            'transcript_text' => $transcript,
            'display_mode' => $payload['display_mode'],
            'screen_mode' => $payload['screen_mode'],
            'accent_color' => $payload['accent_color'] ?? null,
            'layout_spec' => $payload['layout_spec'] ?? null,
        ]);

        $draft['transcript_text'] = $transcript;

        return $draft;
    }

    private function requestDraft(string $clubName, array $payload): array
    {
        $response = $this->openAi()
            ->post('responses', [
                'model' => config('services.openai.club_visual_model', 'gpt-4.1-mini'),
                'temperature' => 0.4,
                'max_output_tokens' => 1400,
                'input' => [
                    [
                        'role' => 'system',
                        'content' => [
                            [
                                'type' => 'input_text',
                                'text' => $this->systemPrompt(),
                            ],
                        ],
                    ],
                    [
                        'role' => 'user',
                        'content' => [
                            [
                                'type' => 'input_text',
                                'text' => $this->userPrompt($clubName, $payload),
                            ],
                        ],
                    ],
                ],
                'text' => [
                    'verbosity' => 'medium',
                    'format' => [
                        'type' => 'json_schema',
                        'name' => 'club_visual_draft',
                        'strict' => true,
                        'schema' => $this->draftSchema(),
                    ],
                ],
            ]);

        if ($response->failed()) {
            Log::warning('club visual ai draft failed', [
                'status' => $response->status(),
                'body' => $response->json(),
            ]);

            throw ValidationException::withMessages([
                'openai' => $this->openAiErrorMessage($response->json()),
            ]);
        }

        $text = $this->extractOutputText($response->json() ?? []);
        $draft = json_decode($text, true);
        if (!is_array($draft)) {
            throw ValidationException::withMessages([
                'openai' => 'AI javobidan JSON draft olinmadi.',
            ]);
        }

        return $draft;
    }

    private function transcribeAudio(string $audioUrl): ?string
    {
        $download = Http::timeout(30)->get($audioUrl);
        if ($download->failed()) {
            throw ValidationException::withMessages([
                'audio_url' => 'Audio faylni yuklab bo‘lmadi.',
            ]);
        }

        $binary = $download->body();
        if ($binary === '') {
            return null;
        }

        $filename = 'club-visual-audio.' . $this->guessAudioExtension($audioUrl, (string) $download->header('Content-Type'));

        $response = $this->openAi()
            ->attach('file', $binary, $filename)
            ->post('audio/transcriptions', [
                'model' => config('services.openai.transcription_model', 'gpt-4o-mini-transcribe'),
                'response_format' => 'text',
            ]);

        if ($response->failed()) {
            Log::warning('club visual transcription failed', [
                'status' => $response->status(),
                'body' => $response->json(),
            ]);

            throw ValidationException::withMessages([
                'audio_url' => $this->openAiErrorMessage($response->json()),
            ]);
        }

        return trim((string) $response->body()) ?: null;
    }

    private function openAi()
    {
        return Http::withToken((string) config('services.openai.api_key'))
            ->acceptJson()
            ->baseUrl((string) config('services.openai.base_url', 'https://api.openai.com/v1'))
            ->timeout(60);
    }

    private function systemPrompt(): string
    {
        return <<<TEXT
You are a senior gaming club visual director.
Return JSON only.
Create a practical draft for a club poster, TV screen card, or promo visual based on the operator brief.
Keep the result commercially useful and easy for a designer or renderer to execute.
Do not invent impossible room details. If the brief is incomplete, make conservative assumptions.
Accent color must be a valid hex color.
TEXT;
    }

    private function userPrompt(string $clubName, array $payload): string
    {
        $parts = [
            "Club name: {$clubName}",
            'Target screen mode: ' . ($payload['screen_mode'] ?? 'poster'),
            'Preferred display mode: ' . ($payload['display_mode'] ?? 'upload'),
            'Preferred accent color: ' . ($payload['accent_color'] ?? 'choose a clean esports-friendly hex color'),
        ];

        if (!empty($payload['prompt_text'])) {
            $parts[] = "Operator text brief:\n" . trim((string) $payload['prompt_text']);
        }

        if (!empty($payload['transcript_text'])) {
            $parts[] = "Audio transcript:\n" . trim((string) $payload['transcript_text']);
        }

        if (!empty($payload['layout_spec']) && is_array($payload['layout_spec'])) {
            $parts[] = "Existing layout context JSON:\n" . json_encode($payload['layout_spec'], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        }

        return implode("\n\n", $parts);
    }

    private function draftSchema(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['headline', 'subheadline', 'description_text', 'accent_color', 'layout_spec', 'visual_spec'],
            'properties' => [
                'headline' => ['type' => 'string'],
                'subheadline' => ['type' => 'string'],
                'description_text' => ['type' => 'string'],
                'accent_color' => ['type' => 'string'],
                'layout_spec' => [
                    'type' => 'object',
                    'additionalProperties' => false,
                    'required' => ['summary', 'zones', 'focal_points'],
                    'properties' => [
                        'summary' => ['type' => 'string'],
                        'zones' => [
                            'type' => 'array',
                            'items' => [
                                'type' => 'object',
                                'additionalProperties' => false,
                                'required' => ['name', 'note'],
                                'properties' => [
                                    'name' => ['type' => 'string'],
                                    'note' => ['type' => 'string'],
                                ],
                            ],
                        ],
                        'focal_points' => [
                            'type' => 'array',
                            'items' => ['type' => 'string'],
                        ],
                    ],
                ],
                'visual_spec' => [
                    'type' => 'object',
                    'additionalProperties' => false,
                    'required' => ['theme', 'mood', 'composition', 'designer_notes', 'image_prompt'],
                    'properties' => [
                        'theme' => ['type' => 'string'],
                        'mood' => ['type' => 'string'],
                        'composition' => ['type' => 'string'],
                        'designer_notes' => [
                            'type' => 'array',
                            'items' => ['type' => 'string'],
                        ],
                        'image_prompt' => ['type' => 'string'],
                    ],
                ],
            ],
        ];
    }

    private function extractOutputText(array $response): string
    {
        $output = $response['output'] ?? [];
        if (!is_array($output)) {
            return '';
        }

        $chunks = [];
        foreach ($output as $item) {
            if (($item['type'] ?? null) !== 'message') {
                continue;
            }

            foreach (($item['content'] ?? []) as $content) {
                if (($content['type'] ?? null) === 'output_text' && is_string($content['text'] ?? null)) {
                    $chunks[] = $content['text'];
                }
            }
        }

        return trim(implode("\n", $chunks));
    }

    private function openAiErrorMessage(?array $payload): string
    {
        $message = data_get($payload, 'error.message');

        return is_string($message) && trim($message) !== ''
            ? $message
            : 'OpenAI so‘rovida xatolik yuz berdi.';
    }

    private function guessAudioExtension(string $url, string $contentType): string
    {
        $pathExt = strtolower(pathinfo(parse_url($url, PHP_URL_PATH) ?: '', PATHINFO_EXTENSION));
        if ($pathExt !== '') {
            return $pathExt;
        }

        return match (strtolower($contentType)) {
            'audio/mpeg', 'audio/mp3' => 'mp3',
            'audio/wav', 'audio/x-wav' => 'wav',
            'audio/mp4', 'audio/aac' => 'm4a',
            'audio/ogg' => 'ogg',
            'audio/webm', 'video/webm' => 'webm',
            default => 'mp3',
        };
    }
}
