<?php

namespace Tests\Feature;

use App\Enums\PcCommandType;
use App\Models\Pc;
use App\Models\PcBooking;
use App\Models\Session;
use App\Models\Setting;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Http\UploadedFile;
use Tests\Feature\Concerns\CreatesTenantApiFixtures;
use Tests\TestCase;

class NexoraAssistantEndpointsTest extends TestCase
{
    use CreatesTenantApiFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-04-23 15:00:00'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_nexora_can_return_hall_snapshot_plan_without_confirmation(): void
    {
        $fixture = $this->createTenantFixture();

        Session::query()->create([
            'tenant_id' => $fixture['tenant']->id,
            'pc_id' => $fixture['pc']->id,
            'client_id' => $fixture['client']->id,
            'operator_id' => $fixture['operator']->id,
            'started_at' => now()->subMinutes(20),
            'status' => 'active',
            'price_total' => 0,
        ]);
        $fixture['pc']->update(['status' => 'busy']);

        Pc::query()->create([
            'tenant_id' => $fixture['tenant']->id,
            'code' => 'PC-2',
            'zone_id' => $fixture['zone']->id,
            'zone' => 'VIP',
            'status' => 'online',
            'last_seen_at' => now(),
            'is_hidden' => false,
        ]);

        $this->actingAsOwner($fixture['operator'])
            ->postJson('/api/nexora-assistant/plan', [
                'message' => 'Hozir zal holati qanday?',
                'locale' => 'uz',
            ])
            ->assertOk()
            ->assertJsonPath('data.action', 'hall_snapshot')
            ->assertJsonPath('data.confirmation_required', false)
            ->assertJsonPath('data.can_execute', false)
            ->assertJsonPath('data.metrics.active_sessions', 1)
            ->assertJsonPath('data.metrics.online_pcs', 2)
            ->assertJsonPath('data.metrics.idle_online_pcs', 1);
    }

    public function test_basic_plan_cannot_access_nexora_ai(): void
    {
        $fixture = $this->createTenantFixture('basic');

        $this->actingAsOwner($fixture['operator'])
            ->getJson('/api/nexora-assistant/overview?locale=uz')
            ->assertStatus(403)
            ->assertJsonPath('feature', 'nexora_ai')
            ->assertJsonPath('upgrade_required', true)
            ->assertJsonPath('plan.code', 'basic');
    }

    public function test_nexora_overview_returns_watch_alerts_and_autopilot_state(): void
    {
        $fixture = $this->createTenantFixture();

        Pc::query()->create([
            'tenant_id' => $fixture['tenant']->id,
            'code' => 'PC-2',
            'zone_id' => $fixture['zone']->id,
            'zone' => 'VIP',
            'status' => 'online',
            'last_seen_at' => now(),
            'is_hidden' => false,
        ]);

        Pc::query()->create([
            'tenant_id' => $fixture['tenant']->id,
            'code' => 'PC-3',
            'zone_id' => $fixture['zone']->id,
            'zone' => 'VIP',
            'status' => 'online',
            'last_seen_at' => now(),
            'is_hidden' => false,
        ]);

        Pc::query()->create([
            'tenant_id' => $fixture['tenant']->id,
            'code' => 'PC-4',
            'zone_id' => $fixture['zone']->id,
            'zone' => 'VIP',
            'status' => 'offline',
            'last_seen_at' => now()->subMinutes(10),
            'is_hidden' => false,
        ]);

        $this->actingAsOwner($fixture['operator'])
            ->getJson('/api/nexora-assistant/overview?locale=uz')
            ->assertOk()
            ->assertJsonPath('data.summary.title', 'Nexora Watch')
            ->assertJsonPath('data.metrics.idle_online_pcs', 3)
            ->assertJsonPath('data.metrics.offline_pcs', 1)
            ->assertJsonPath('data.autopilot.enabled', false)
            ->assertJsonPath('data.autopilot.can_manage', true)
            ->assertJsonCount(3, 'data.alerts');
    }

    public function test_nexora_autopilot_settings_can_be_updated(): void
    {
        $fixture = $this->createTenantFixture();

        $this->actingAsOwner($fixture['operator'])
            ->postJson('/api/nexora-assistant/autopilot', [
                'enabled' => true,
                'auto_lock_idle_online' => true,
                'suggest_idle_shutdown' => false,
                'suggest_offline_watch' => true,
                'locale' => 'uz',
            ])
            ->assertOk()
            ->assertJsonPath('data.autopilot.enabled', true)
            ->assertJsonPath('data.autopilot.auto_lock_idle_online', true)
            ->assertJsonPath('data.autopilot.suggest_idle_shutdown', false);

        $stored = Setting::query()
            ->where('tenant_id', $fixture['tenant']->id)
            ->where('key', 'nexora_autopilot')
            ->first()?->value;

        $this->assertSame([
            'enabled' => true,
            'auto_lock_idle_online' => true,
            'suggest_idle_shutdown' => false,
            'suggest_offline_watch' => true,
        ], $stored);
    }

    public function test_nexora_autopilot_tick_can_lock_idle_online_pcs(): void
    {
        $fixture = $this->createTenantFixture();

        Pc::query()->create([
            'tenant_id' => $fixture['tenant']->id,
            'code' => 'PC-2',
            'zone_id' => $fixture['zone']->id,
            'zone' => 'VIP',
            'status' => 'online',
            'last_seen_at' => now(),
            'is_hidden' => false,
        ]);

        Setting::query()->create([
            'tenant_id' => $fixture['tenant']->id,
            'key' => 'nexora_autopilot',
            'value' => [
                'enabled' => true,
                'auto_lock_idle_online' => true,
                'suggest_idle_shutdown' => true,
                'suggest_offline_watch' => true,
            ],
        ]);

        $this->artisan('nexora:autopilot-tick')
            ->assertExitCode(0);

        $this->assertDatabaseHas('pc_commands', [
            'tenant_id' => $fixture['tenant']->id,
            'pc_id' => $fixture['pc']->id,
            'type' => PcCommandType::Lock->value,
            'status' => 'pending',
        ]);
        $this->assertDatabaseHas('events', [
            'tenant_id' => $fixture['tenant']->id,
            'type' => 'nexora_autopilot_lock_executed',
            'source' => 'assistant',
        ]);
    }

    public function test_nexora_can_generate_tts_audio_response(): void
    {
        $fixture = $this->createTenantFixture();
        config()->set('services.openai.api_key', 'test-openai-key');

        Http::fake([
            'https://api.openai.com/v1/audio/speech' => Http::response('fake-mp3-audio', 200, [
                'Content-Type' => 'audio/mpeg',
            ]),
        ]);

        $this->actingAsOwner($fixture['operator'])
            ->post('/api/nexora-assistant/speak', [
                'text' => 'Salom, men Nexora AI man.',
                'locale' => 'uz',
            ])
            ->assertOk()
            ->assertHeader('Content-Type', 'audio/mpeg')
            ->assertHeader('X-Nexora-Voice', 'marin')
            ->assertSeeText('fake-mp3-audio');
    }

    public function test_nexora_can_transcribe_audio_prompt(): void
    {
        $fixture = $this->createTenantFixture();
        config()->set('services.openai.api_key', 'test-openai-key');

        Http::fake([
            'https://api.openai.com/v1/audio/transcriptions' => Http::response('Salom, nechta kompyuter yoqiq turibti?', 200),
        ]);

        $audio = UploadedFile::fake()->createWithContent('nexora-command.m4a', 'fake-audio');

        $this->actingAsOwner($fixture['operator'])
            ->post('/api/nexora-assistant/transcribe', [
                'audio' => $audio,
                'locale' => 'uz',
            ])
            ->assertOk()
            ->assertJsonPath('data.text', 'Salom, nechta kompyuter yoqiq turibti?')
            ->assertJsonPath('data.locale', 'uz');
    }

    public function test_nexora_shutdown_idle_plan_requires_confirmation_and_executes_batch(): void
    {
        $fixture = $this->createTenantFixture();

        $eligibleOnline = Pc::query()->create([
            'tenant_id' => $fixture['tenant']->id,
            'code' => 'PC-2',
            'zone_id' => $fixture['zone']->id,
            'zone' => 'VIP',
            'status' => 'online',
            'last_seen_at' => now(),
            'is_hidden' => false,
        ]);

        $eligibleLocked = Pc::query()->create([
            'tenant_id' => $fixture['tenant']->id,
            'code' => 'PC-3',
            'zone_id' => $fixture['zone']->id,
            'zone' => 'VIP',
            'status' => 'locked',
            'last_seen_at' => now(),
            'is_hidden' => false,
        ]);

        $reservedPc = Pc::query()->create([
            'tenant_id' => $fixture['tenant']->id,
            'code' => 'PC-4',
            'zone_id' => $fixture['zone']->id,
            'zone' => 'VIP',
            'status' => 'online',
            'last_seen_at' => now(),
            'is_hidden' => false,
        ]);

        PcBooking::query()->create([
            'tenant_id' => $fixture['tenant']->id,
            'pc_id' => $reservedPc->id,
            'client_id' => $fixture['client']->id,
            'reserved_from' => now()->subMinutes(5),
            'reserved_until' => now()->addMinutes(25),
        ]);

        Session::query()->create([
            'tenant_id' => $fixture['tenant']->id,
            'pc_id' => $fixture['pc']->id,
            'client_id' => $fixture['client']->id,
            'operator_id' => $fixture['operator']->id,
            'started_at' => now()->subMinutes(15),
            'status' => 'active',
            'price_total' => 0,
        ]);
        $fixture['pc']->update(['status' => 'busy']);

        $planResponse = $this->actingAsOwner($fixture['operator'])
            ->postJson('/api/nexora-assistant/plan', [
                'message' => "Barcha yoniq va odam yo'q kompyuterlarni o'chir",
                'locale' => 'uz',
            ]);

        $planResponse
            ->assertOk()
            ->assertJsonPath('data.action', 'shutdown_idle_pcs')
            ->assertJsonPath('data.confirmation_required', true)
            ->assertJsonPath('data.target_count', 2);

        $planId = (string) $planResponse->json('data.plan_id');

        $this->assertNotSame('', $planId);

        $executeResponse = $this->actingAsOwner($fixture['operator'])
            ->postJson('/api/nexora-assistant/execute', [
                'plan_id' => $planId,
                'confirmed' => true,
                'locale' => 'uz',
            ]);

        $executeResponse
            ->assertOk()
            ->assertJsonPath('data.status', 'executed')
            ->assertJsonPath('data.executed_count', 2)
            ->assertJsonCount(2, 'data.command_ids');

        $this->assertDatabaseHas('pc_commands', [
            'tenant_id' => $fixture['tenant']->id,
            'pc_id' => $eligibleOnline->id,
            'type' => PcCommandType::Shutdown->value,
            'status' => 'pending',
        ]);
        $this->assertDatabaseHas('pc_commands', [
            'tenant_id' => $fixture['tenant']->id,
            'pc_id' => $eligibleLocked->id,
            'type' => PcCommandType::Shutdown->value,
            'status' => 'pending',
        ]);
        $this->assertDatabaseMissing('pc_commands', [
            'tenant_id' => $fixture['tenant']->id,
            'pc_id' => $reservedPc->id,
            'type' => PcCommandType::Shutdown->value,
        ]);
        $this->assertDatabaseHas('events', [
            'tenant_id' => $fixture['tenant']->id,
            'type' => 'nexora_plan_created',
            'source' => 'assistant',
            'entity_type' => 'operator',
            'entity_id' => $fixture['operator']->id,
        ]);
        $this->assertDatabaseHas('events', [
            'tenant_id' => $fixture['tenant']->id,
            'type' => 'nexora_command_batch_executed',
            'source' => 'assistant',
            'entity_type' => 'operator',
            'entity_id' => $fixture['operator']->id,
        ]);
    }

    public function test_nexora_execute_requires_confirmation_for_destructive_plan(): void
    {
        $fixture = $this->createTenantFixture();

        $planResponse = $this->actingAsOwner($fixture['operator'])
            ->postJson('/api/nexora-assistant/plan', [
                'message' => "Bo'sh yoniq kompyuterlarni o'chir",
                'locale' => 'uz',
            ]);

        $planId = (string) $planResponse->json('data.plan_id');

        $this->actingAsOwner($fixture['operator'])
            ->postJson('/api/nexora-assistant/execute', [
                'plan_id' => $planId,
                'confirmed' => false,
                'locale' => 'uz',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['confirmed']);
    }

    public function test_nexora_can_plan_and_execute_lock_for_idle_online_pcs(): void
    {
        $fixture = $this->createTenantFixture();

        $extraPc = Pc::query()->create([
            'tenant_id' => $fixture['tenant']->id,
            'code' => 'PC-2',
            'zone_id' => $fixture['zone']->id,
            'zone' => 'VIP',
            'status' => 'online',
            'last_seen_at' => now(),
            'is_hidden' => false,
        ]);

        $planResponse = $this->actingAsOwner($fixture['operator'])
            ->postJson('/api/nexora-assistant/plan', [
                'message' => "Bo'sh online kompyuterlarni lock qil",
                'locale' => 'uz',
            ]);

        $planResponse
            ->assertOk()
            ->assertJsonPath('data.action', 'lock_idle_pcs')
            ->assertJsonPath('data.target_count', 2)
            ->assertJsonPath('data.confirmation_required', true);

        $planId = (string) $planResponse->json('data.plan_id');

        $this->actingAsOwner($fixture['operator'])
            ->postJson('/api/nexora-assistant/execute', [
                'plan_id' => $planId,
                'confirmed' => true,
                'locale' => 'uz',
            ])
            ->assertOk()
            ->assertJsonPath('data.executed_count', 2);

        $this->assertDatabaseHas('pc_commands', [
            'tenant_id' => $fixture['tenant']->id,
            'pc_id' => $fixture['pc']->id,
            'type' => PcCommandType::Lock->value,
        ]);
        $this->assertDatabaseHas('pc_commands', [
            'tenant_id' => $fixture['tenant']->id,
            'pc_id' => $extraPc->id,
            'type' => PcCommandType::Lock->value,
        ]);
    }

    public function test_nexora_can_plan_and_execute_reboot_for_named_pcs(): void
    {
        $fixture = $this->createTenantFixture();

        $extraPc = Pc::query()->create([
            'tenant_id' => $fixture['tenant']->id,
            'code' => 'PC-2',
            'zone_id' => $fixture['zone']->id,
            'zone' => 'VIP',
            'status' => 'online',
            'last_seen_at' => now(),
            'is_hidden' => false,
        ]);

        $planResponse = $this->actingAsOwner($fixture['operator'])
            ->postJson('/api/nexora-assistant/plan', [
                'message' => 'PC-1 va PC-2 ni reboot qil',
                'locale' => 'uz',
            ]);

        $planResponse
            ->assertOk()
            ->assertJsonPath('data.action', 'reboot_named_pcs')
            ->assertJsonPath('data.target_count', 2);

        $planId = (string) $planResponse->json('data.plan_id');

        $this->actingAsOwner($fixture['operator'])
            ->postJson('/api/nexora-assistant/execute', [
                'plan_id' => $planId,
                'confirmed' => true,
                'locale' => 'uz',
            ])
            ->assertOk()
            ->assertJsonPath('data.executed_count', 2);

        $this->assertDatabaseHas('pc_commands', [
            'tenant_id' => $fixture['tenant']->id,
            'pc_id' => $fixture['pc']->id,
            'type' => PcCommandType::Reboot->value,
        ]);
        $this->assertDatabaseHas('pc_commands', [
            'tenant_id' => $fixture['tenant']->id,
            'pc_id' => $extraPc->id,
            'type' => PcCommandType::Reboot->value,
        ]);
    }

    public function test_nexora_can_plan_and_execute_zone_message(): void
    {
        $fixture = $this->createTenantFixture();

        $vipPc = Pc::query()->create([
            'tenant_id' => $fixture['tenant']->id,
            'code' => 'PC-2',
            'zone_id' => $fixture['zone']->id,
            'zone' => 'VIP',
            'status' => 'online',
            'last_seen_at' => now(),
            'is_hidden' => false,
        ]);

        $otherZone = \App\Models\Zone::query()->create([
            'tenant_id' => $fixture['tenant']->id,
            'name' => 'BOOTCAMP',
            'price_per_hour' => 12000,
            'is_active' => true,
        ]);

        $otherPc = Pc::query()->create([
            'tenant_id' => $fixture['tenant']->id,
            'code' => 'PC-3',
            'zone_id' => $otherZone->id,
            'zone' => 'BOOTCAMP',
            'status' => 'online',
            'last_seen_at' => now(),
            'is_hidden' => false,
        ]);

        $planResponse = $this->actingAsOwner($fixture['operator'])
            ->postJson('/api/nexora-assistant/plan', [
                'message' => 'VIP zonaga "10 daqiqada turnir boshlanadi" deb xabar yubor',
                'locale' => 'uz',
            ]);

        $planResponse
            ->assertOk()
            ->assertJsonPath('data.action', 'message_zone_pcs')
            ->assertJsonPath('data.target_count', 2);

        $planId = (string) $planResponse->json('data.plan_id');

        $this->actingAsOwner($fixture['operator'])
            ->postJson('/api/nexora-assistant/execute', [
                'plan_id' => $planId,
                'confirmed' => true,
                'locale' => 'uz',
            ])
            ->assertOk()
            ->assertJsonPath('data.executed_count', 2);

        $this->assertDatabaseHas('pc_commands', [
            'tenant_id' => $fixture['tenant']->id,
            'pc_id' => $fixture['pc']->id,
            'type' => PcCommandType::Message->value,
        ]);
        $this->assertDatabaseHas('pc_commands', [
            'tenant_id' => $fixture['tenant']->id,
            'pc_id' => $vipPc->id,
            'type' => PcCommandType::Message->value,
        ]);
        $this->assertDatabaseMissing('pc_commands', [
            'tenant_id' => $fixture['tenant']->id,
            'pc_id' => $otherPc->id,
            'type' => PcCommandType::Message->value,
        ]);
    }

    public function test_nexora_can_return_today_revenue_and_offline_pc_list(): void
    {
        $fixture = $this->createTenantFixture();
        $fixture['pc']->update([
            'status' => 'offline',
            'last_seen_at' => now()->subMinutes(30),
        ]);

        $this->actingAsOwner($fixture['operator'])
            ->postJson('/api/nexora-assistant/plan', [
                'message' => 'Bugungi tushum qancha?',
                'locale' => 'uz',
            ])
            ->assertOk()
            ->assertJsonPath('data.action', 'today_revenue')
            ->assertJsonPath('data.can_execute', false);

        $this->actingAsOwner($fixture['operator'])
            ->postJson('/api/nexora-assistant/plan', [
                'message' => 'Offline kompyuterlarni ko‘rsat',
                'locale' => 'uz',
            ])
            ->assertOk()
            ->assertJsonPath('data.action', 'offline_pc_list')
            ->assertJsonPath('data.target_count', 1)
            ->assertJsonPath('data.targets.0.code', 'PC-1');
    }
}
