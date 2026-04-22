<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Services\TenantSettingService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Concerns\CreatesTenantApiFixtures;
use Tests\TestCase;

class SettingsEndpointsTest extends TestCase
{
    use CreatesTenantApiFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-04-21 12:00:00'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_settings_index_normalizes_public_urls_for_current_host(): void
    {
        $fixture = $this->createTenantFixture();

        Setting::query()->create([
            'tenant_id' => $fixture['tenant']->id,
            'key' => 'deploy_agent_download_url',
            'value' => '/storage/agents/setup.exe',
        ]);

        $response = $this->actingAsOwner($fixture['operator'])
            ->getJson('/api/settings');

        $response->assertOk();

        $settings = collect($response->json('data'))->keyBy('key');

        $this->assertSame(
            'http://localhost/storage/agents/setup.exe',
            $settings->get('deploy_agent_download_url')['value'] ?? null,
        );
    }

    public function test_settings_update_persists_valid_payload(): void
    {
        $fixture = $this->createTenantFixture();

        $response = $this->actingAsOwner($fixture['operator'])
            ->postJson('/api/settings', [
                'settings' => [
                    'club_name' => 'Test Club Pro',
                    'telegram_shift_notifications' => true,
                    'auto_shift_enabled' => true,
                    'auto_shift_slots' => [
                        ['start' => '09:00', 'end' => '21:00', 'label' => 'Day'],
                    ],
                ],
            ]);

        $response
            ->assertOk()
            ->assertJsonPath('ok', true);

        $settings = app(TenantSettingService::class);

        $this->assertSame('Test Club Pro', $settings->get($fixture['tenant']->id, 'club_name'));
        $this->assertTrue($settings->get($fixture['tenant']->id, 'telegram_shift_notifications', false));
        $this->assertTrue($settings->get($fixture['tenant']->id, 'auto_shift_enabled', false));
        $slots = $settings->get($fixture['tenant']->id, 'auto_shift_slots', []);

        $this->assertCount(1, $slots);
        $this->assertSame('09:00', $slots[0]['start'] ?? null);
        $this->assertSame('21:00', $slots[0]['end'] ?? null);
        $this->assertSame('Day', $slots[0]['label'] ?? null);
    }

    public function test_settings_update_rejects_unknown_keys(): void
    {
        $fixture = $this->createTenantFixture();

        $response = $this->actingAsOwner($fixture['operator'])
            ->postJson('/api/settings', [
                'settings' => [
                    'unknown_setting' => 'value',
                ],
            ]);

        $response
            ->assertStatus(422)
            ->assertJsonValidationErrors(['settings.unknown_setting']);
    }

    public function test_upload_agent_installer_persists_url_and_sha256(): void
    {
        Storage::fake('public');

        $fixture = $this->createTenantFixture();
        $content = 'agent-installer-binary';
        $file = UploadedFile::fake()->createWithContent('agent-installer.exe', $content);

        $response = $this->actingAsOwner($fixture['operator'])
            ->post('/api/settings/agent-installer', [
                'file' => $file,
            ]);

        $response
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('deploy_agent_sha256', hash('sha256', $content));

        $settings = app(TenantSettingService::class);
        $url = (string) $settings->get($fixture['tenant']->id, 'deploy_agent_download_url');

        $this->assertStringContainsString('/storage/agent_installers/', $url);
        $this->assertSame(hash('sha256', $content), $settings->get($fixture['tenant']->id, 'deploy_agent_sha256'));
    }
}
