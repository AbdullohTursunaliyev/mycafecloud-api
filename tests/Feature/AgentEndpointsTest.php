<?php

namespace Tests\Feature;

use App\Enums\PcCommandType;
use App\Models\Pc;
use App\Models\PcCommand;
use App\Models\PcDeviceToken;
use App\Models\PcPairCode;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\CreatesTenantApiFixtures;
use Tests\TestCase;

class AgentEndpointsTest extends TestCase
{
    use CreatesTenantApiFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-04-22 10:00:00'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_agent_pair_creates_pc_marks_pair_used_and_issues_device_token(): void
    {
        $fixture = $this->createTenantFixture();

        $pair = PcPairCode::query()->create([
            'tenant_id' => $fixture['tenant']->id,
            'code' => 'PAIR-01',
            'zone' => 'VIP',
            'expires_at' => now()->addMinutes(15),
        ]);

        $response = $this->postJson('/api/agent/pair', [
            'pair_code' => $pair->code,
            'pc_name' => 'PC-NEW-1',
            'ip' => '10.0.0.15',
            'os' => 'Windows 11',
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('pc.code', 'PC-NEW-1')
            ->assertJsonPath('pc.zone', 'VIP');

        $pcId = (int) $response->json('pc.id');
        $deviceToken = (string) $response->json('device_token');

        $this->assertNotSame('', $deviceToken);
        $this->assertDatabaseHas('pcs', [
            'id' => $pcId,
            'tenant_id' => $fixture['tenant']->id,
            'code' => 'PC-NEW-1',
            'zone' => 'VIP',
            'status' => 'online',
            'ip_address' => '10.0.0.15',
        ]);
        $this->assertNotNull($pair->fresh()->used_at);
        $this->assertSame($pcId, (int) $pair->fresh()->pc_id);
        $this->assertDatabaseHas('pc_device_tokens', [
            'tenant_id' => $fixture['tenant']->id,
            'pc_id' => $pcId,
            'revoked_at' => null,
        ]);
    }

    public function test_agent_settings_rotates_expiring_device_token_and_returns_settings(): void
    {
        $fixture = $this->createTenantFixture();
        $oldToken = $this->issueDeviceToken($fixture['tenant'], $fixture['pc'], now()->addHour());

        $response = $this->withHeaders($this->deviceHeaders($oldToken))
            ->getJson('/api/agent/settings');

        $response
            ->assertOk()
            ->assertHeader('X-Device-Token-Rotate')
            ->assertJsonStructure([
                'settings' => [
                    'deploy_agent_download_url',
                    'poll_interval_sec',
                ],
                'device_token',
                'device_token_expires_at',
            ]);

        $newToken = (string) $response->json('device_token');
        $this->assertNotSame($oldToken, $newToken);

        $oldRow = PcDeviceToken::query()
            ->where('tenant_id', $fixture['tenant']->id)
            ->where('pc_id', $fixture['pc']->id)
            ->orderBy('id')
            ->firstOrFail();

        $this->assertNotNull($oldRow->revoked_at);
        $this->assertSame('rotated', $oldRow->revocation_reason);
        $this->assertSame(2, PcDeviceToken::query()->where('tenant_id', $fixture['tenant']->id)->count());
    }

    public function test_agent_heartbeat_updates_pc_and_logs_metrics(): void
    {
        $fixture = $this->createTenantFixture();
        $token = $this->issueDeviceToken($fixture['tenant'], $fixture['pc']);

        $response = $this->withHeaders($this->deviceHeaders($token))
            ->postJson('/api/agent/heartbeat', [
                'ip' => '10.10.10.10',
                'status' => 'maintenance',
                'metrics' => [
                    'cpu' => 55,
                    'ram' => 42,
                    'game' => 'CS2',
                ],
            ]);

        $response
            ->assertOk()
            ->assertJsonPath('ok', true);

        $this->assertDatabaseHas('pcs', [
            'id' => $fixture['pc']->id,
            'ip_address' => '10.10.10.10',
            'status' => 'maintenance',
        ]);
        $this->assertDatabaseHas('pc_heartbeats', [
            'tenant_id' => $fixture['tenant']->id,
            'pc_id' => $fixture['pc']->id,
        ]);
    }

    public function test_agent_poll_returns_pending_commands_and_marks_them_sent(): void
    {
        $fixture = $this->createTenantFixture();
        $token = $this->issueDeviceToken($fixture['tenant'], $fixture['pc']);

        $command = PcCommand::query()->create([
            'tenant_id' => $fixture['tenant']->id,
            'pc_id' => $fixture['pc']->id,
            'type' => PcCommandType::Message->value,
            'payload' => ['text' => 'Hello agent'],
            'status' => 'pending',
        ]);

        $response = $this->withHeaders($this->deviceHeaders($token))
            ->getJson('/api/agent/commands/poll');

        $response
            ->assertOk()
            ->assertJsonPath('commands.0.id', $command->id)
            ->assertJsonPath('commands.0.type', PcCommandType::Message->value)
            ->assertJsonPath('commands.0.payload.text', 'Hello agent');

        $this->assertSame('sent', (string) $command->fresh()->status);
        $this->assertNotNull($command->fresh()->sent_at);
    }

    private function deviceHeaders(string $token): array
    {
        return [
            'Authorization' => 'Bearer ' . $token,
        ];
    }
}
