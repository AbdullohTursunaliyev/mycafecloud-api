<?php

namespace Tests\Feature;

use App\Models\Pc;
use App\Models\Zone;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Concerns\CreatesTenantApiFixtures;
use Tests\TestCase;

class ShellBannerEndpointsTest extends TestCase
{
    use CreatesTenantApiFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-04-23 14:30:00'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_owner_can_manage_shell_banners_and_logo_is_required(): void
    {
        ['operator' => $operator, 'zone' => $zone, 'pc' => $pc] = $this->createTenantFixture();

        $this->actingAsOwner($operator)
            ->postJson('/api/shell-banners', [
                'name' => 'VIP Queue Banner',
                'headline' => 'Turnir 20:00 da',
                'target_scope' => 'all',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['logo_url']);

        $create = $this->actingAsOwner($operator)
            ->postJson('/api/shell-banners', [
                'name' => 'VIP Queue Banner',
                'headline' => 'Turnir 20:00 da',
                'subheadline' => 'Joylarni oldindan band qiling',
                'body_text' => 'Kechki prime time uchun navbatni oldindan kuzating.',
                'cta_text' => 'Operatorga murojaat qiling',
                'prompt_text' => 'Cyan neon banner with VIP logo and tournament callout.',
                'logo_url' => 'https://cdn.example.com/logo.png',
                'image_url' => 'https://cdn.example.com/banner.png',
                'audio_url' => 'https://cdn.example.com/banner-brief.mp3',
                'accent_color' => '#62E6FF',
                'target_scope' => 'zones',
                'target_zone_ids' => [$zone->id],
                'display_seconds' => 18,
                'sort_order' => 5,
                'starts_at' => '2026-04-23T12:00:00+05:00',
                'ends_at' => '2026-04-23T23:00:00+05:00',
                'is_active' => true,
            ]);

        $create->assertCreated()
            ->assertJsonPath('data.name', 'VIP Queue Banner')
            ->assertJsonPath('data.logo_url', 'https://cdn.example.com/logo.png')
            ->assertJsonPath('data.target_scope', 'zones')
            ->assertJsonPath('data.target_zone_ids.0', $zone->id);

        $bannerId = (int) $create->json('data.id');

        $this->actingAsOwner($operator)
            ->getJson('/api/shell-banners')
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $this->actingAsOwner($operator)
            ->patchJson("/api/shell-banners/{$bannerId}", [
                'target_scope' => 'pcs',
                'target_pc_ids' => [$pc->id],
                'sort_order' => 1,
            ])
            ->assertOk()
            ->assertJsonPath('data.target_scope', 'pcs')
            ->assertJsonPath('data.target_pc_ids.0', $pc->id)
            ->assertJsonPath('data.sort_order', 1);

        $this->actingAsOwner($operator)
            ->postJson("/api/shell-banners/{$bannerId}/toggle")
            ->assertOk()
            ->assertJsonPath('data.is_active', false);

        $this->actingAsOwner($operator)
            ->deleteJson("/api/shell-banners/{$bannerId}")
            ->assertOk()
            ->assertJsonPath('ok', true);
    }

    public function test_owner_can_upload_shell_banner_assets(): void
    {
        ['operator' => $operator] = $this->createTenantFixture();
        Storage::fake('public');

        $logoResponse = $this->actingAsOwner($operator)
            ->post('/api/shell-banners/upload-logo', [
                'file' => UploadedFile::fake()->image('club-logo.png', 512, 512),
            ])
            ->assertOk()
            ->assertJsonPath('ok', true);

        $this->assertStringContainsString('/storage/shell_banners/logos/', (string) $logoResponse->json('url'));

        $imageResponse = $this->actingAsOwner($operator)
            ->post('/api/shell-banners/upload-image', [
                'file' => UploadedFile::fake()->image('banner.png', 1920, 1080),
            ])
            ->assertOk()
            ->assertJsonPath('ok', true);

        $this->assertStringContainsString('/storage/shell_banners/images/', (string) $imageResponse->json('url'));

        $audioResponse = $this->actingAsOwner($operator)
            ->post('/api/shell-banners/upload-audio', [
                'file' => UploadedFile::fake()->create('banner-note.mp3', 64, 'audio/mpeg'),
            ])
            ->assertOk()
            ->assertJsonPath('ok', true);

        $this->assertStringContainsString('/storage/shell_banners/audio/', (string) $audioResponse->json('url'));
    }

    public function test_agent_manifest_returns_only_matching_active_shell_banners(): void
    {
        $fixture = $this->createTenantFixture();
        $otherZone = Zone::query()->create([
            'tenant_id' => $fixture['tenant']->id,
            'name' => 'Bootcamp',
            'price_per_hour' => 12000,
            'is_active' => true,
        ]);
        $otherPc = Pc::query()->create([
            'tenant_id' => $fixture['tenant']->id,
            'code' => 'PC-2',
            'zone_id' => $otherZone->id,
            'zone' => 'Bootcamp',
            'status' => 'online',
            'last_seen_at' => now(),
            'is_hidden' => false,
        ]);

        $headers = ['Authorization' => 'Bearer ' . $this->issueDeviceToken($fixture['tenant'], $fixture['pc'])];

        $this->actingAsOwner($fixture['operator'])->postJson('/api/shell-banners', [
            'name' => 'Global Banner',
            'headline' => 'Hammasiga ko‘rinadi',
            'prompt_text' => 'Global banner prompt',
            'logo_url' => 'https://cdn.example.com/global-logo.png',
            'audio_url' => 'https://cdn.example.com/global-brief.mp3',
            'target_scope' => 'all',
            'display_seconds' => 10,
        ])->assertCreated();

        $this->actingAsOwner($fixture['operator'])->postJson('/api/shell-banners', [
            'name' => 'VIP Banner',
            'headline' => 'Faqat VIP',
            'logo_url' => 'https://cdn.example.com/vip-logo.png',
            'target_scope' => 'zones',
            'target_zone_ids' => [$fixture['zone']->id],
            'display_seconds' => 15,
        ])->assertCreated();

        $this->actingAsOwner($fixture['operator'])->postJson('/api/shell-banners', [
            'name' => 'Other PC Banner',
            'headline' => 'Faqat boshqa PC',
            'logo_url' => 'https://cdn.example.com/pc-logo.png',
            'target_scope' => 'pcs',
            'target_pc_ids' => [$otherPc->id],
            'display_seconds' => 12,
        ])->assertCreated();

        $this->actingAsOwner($fixture['operator'])->postJson('/api/shell-banners', [
            'name' => 'Expired Banner',
            'headline' => 'Muddati tugagan',
            'logo_url' => 'https://cdn.example.com/expired-logo.png',
            'target_scope' => 'all',
            'starts_at' => '2026-04-22T10:00:00+05:00',
            'ends_at' => '2026-04-22T12:00:00+05:00',
        ])->assertCreated();

        $this->withHeaders($headers)
            ->getJson('/api/agent/shell-banners')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.name', 'Global Banner')
            ->assertJsonPath('data.0.prompt_text', 'Global banner prompt')
            ->assertJsonPath('data.0.audio_url', 'https://cdn.example.com/global-brief.mp3')
            ->assertJsonPath('data.1.name', 'VIP Banner');
    }
}
