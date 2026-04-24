<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Concerns\CreatesTenantApiFixtures;
use Tests\TestCase;

class ClubVisualEndpointsTest extends TestCase
{
    use CreatesTenantApiFixtures;
    use RefreshDatabase;

    public function test_owner_can_manage_club_visuals(): void
    {
        ['operator' => $operator] = $this->createTenantFixture();

        $create = $this->actingAsOwner($operator)->postJson('/api/club-visuals', [
            'name' => 'Main TV Poster',
            'headline' => 'A-ZONE',
            'subheadline' => 'Esports arena',
            'description_text' => 'Katta ekran uchun asosiy poster.',
            'prompt_text' => 'VIP zona o‘ngda, kassir kirishda.',
            'display_mode' => 'upload',
            'screen_mode' => 'tv',
            'accent_color' => '#62E6FF',
            'image_url' => 'https://cdn.example.com/club-visual.png',
            'audio_url' => 'https://cdn.example.com/club-visual.mp3',
            'layout_spec' => ['zones' => [['name' => 'VIP']]],
            'visual_spec' => ['theme' => 'cyber'],
            'sort_order' => 10,
            'is_active' => true,
        ]);

        $create->assertCreated()
            ->assertJsonPath('data.name', 'Main TV Poster')
            ->assertJsonPath('data.screen_mode', 'tv')
            ->assertJsonPath('data.layout_spec.zones.0.name', 'VIP');

        $visualId = (int) $create->json('data.id');

        $this->actingAsOwner($operator)
            ->getJson('/api/club-visuals')
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $this->actingAsOwner($operator)
            ->patchJson("/api/club-visuals/{$visualId}", [
                'headline' => 'A-ZONE PRIME',
                'display_mode' => 'hybrid',
                'screen_mode' => 'poster',
                'sort_order' => 5,
                'is_active' => false,
            ])
            ->assertOk()
            ->assertJsonPath('data.headline', 'A-ZONE PRIME')
            ->assertJsonPath('data.display_mode', 'hybrid')
            ->assertJsonPath('data.screen_mode', 'poster')
            ->assertJsonPath('data.is_active', false);

        $this->actingAsOwner($operator)
            ->postJson("/api/club-visuals/{$visualId}/toggle")
            ->assertOk()
            ->assertJsonPath('data.is_active', true);

        $this->actingAsOwner($operator)
            ->deleteJson("/api/club-visuals/{$visualId}")
            ->assertOk()
            ->assertJsonPath('ok', true);

        $this->actingAsOwner($operator)
            ->getJson('/api/club-visuals')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_owner_can_upload_visual_assets(): void
    {
        ['operator' => $operator] = $this->createTenantFixture();
        Storage::fake('public');

        $imageResponse = $this->actingAsOwner($operator)
            ->post('/api/club-visuals/upload-image', [
                'file' => UploadedFile::fake()->image('club-visual.png', 1200, 900),
            ])
            ->assertOk()
            ->assertJsonPath('ok', true);

        $this->assertStringContainsString('/storage/club_visuals/images/', (string) $imageResponse->json('url'));

        $audioResponse = $this->actingAsOwner($operator)
            ->post('/api/club-visuals/upload-audio', [
                'file' => UploadedFile::fake()->create('club-note.mp3', 64, 'audio/mpeg'),
            ])
            ->assertOk()
            ->assertJsonPath('ok', true);

        $this->assertStringContainsString('/storage/club_visuals/audio/', (string) $audioResponse->json('url'));
    }

    public function test_owner_can_generate_ai_draft_from_text_and_audio(): void
    {
        ['operator' => $operator] = $this->createTenantFixture();

        config()->set('services.openai.api_key', 'test-key');
        config()->set('services.openai.base_url', 'https://api.openai.com/v1');
        config()->set('services.openai.club_visual_model', 'gpt-4.1-mini');
        config()->set('services.openai.transcription_model', 'gpt-4o-mini-transcribe');

        Http::fake([
            'https://assets.example.com/voice.mp3' => Http::response('audio-binary', 200, [
                'Content-Type' => 'audio/mpeg',
            ]),
            'https://api.openai.com/v1/audio/transcriptions' => Http::response('VIP qator o‘ng tomonda, kassir kirishda.', 200),
            'https://api.openai.com/v1/responses' => Http::response([
                'output' => [[
                    'type' => 'message',
                    'content' => [[
                        'type' => 'output_text',
                        'text' => json_encode([
                            'headline' => 'A-ZONE ARENA',
                            'subheadline' => 'Premium esports club',
                            'description_text' => 'Katta ekran uchun neon poster draft.',
                            'accent_color' => '#62E6FF',
                            'layout_spec' => [
                                'summary' => 'VIP o‘ng tomonda, markazda asosiy zal.',
                                'zones' => [
                                    ['name' => 'VIP', 'note' => 'O‘ng tomondagi premium qator'],
                                ],
                                'focal_points' => ['cashier entrance', 'vip row'],
                            ],
                            'visual_spec' => [
                                'theme' => 'cyber arena',
                                'mood' => 'premium competitive',
                                'composition' => 'wide entrance shot with vip emphasis',
                                'designer_notes' => ['blue neon rim light'],
                                'image_prompt' => 'futuristic esports club poster with VIP right wing and cashier at entrance',
                            ],
                        ], JSON_UNESCAPED_UNICODE),
                    ]],
                ]],
            ], 200),
        ]);

        $this->actingAsOwner($operator)
            ->postJson('/api/club-visuals/generate-draft', [
                'prompt_text' => 'Katta ekran uchun premium poster kerak.',
                'audio_url' => 'https://assets.example.com/voice.mp3',
                'display_mode' => 'hybrid',
                'screen_mode' => 'tv',
                'accent_color' => '#00D1FF',
                'layout_spec' => ['zones' => [['name' => 'Main hall']]],
            ])
            ->assertOk()
            ->assertJsonPath('data.headline', 'A-ZONE ARENA')
            ->assertJsonPath('data.subheadline', 'Premium esports club')
            ->assertJsonPath('data.accent_color', '#62E6FF')
            ->assertJsonPath('data.layout_spec.zones.0.name', 'VIP')
            ->assertJsonPath('data.visual_spec.theme', 'cyber arena')
            ->assertJsonPath('data.transcript_text', 'VIP qator o‘ng tomonda, kassir kirishda.');
    }

    public function test_basic_plan_cannot_generate_ai_visual_draft(): void
    {
        ['operator' => $operator] = $this->createTenantFixture('basic');

        $this->actingAsOwner($operator)
            ->postJson('/api/club-visuals/generate-draft', [
                'prompt_text' => 'Premium poster kerak.',
            ])
            ->assertStatus(403)
            ->assertJsonPath('feature', 'ai_generation')
            ->assertJsonPath('upgrade_required', true)
            ->assertJsonPath('plan.code', 'basic');
    }
}
