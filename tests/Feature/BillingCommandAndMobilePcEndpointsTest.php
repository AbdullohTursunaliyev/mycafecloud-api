<?php

namespace Tests\Feature;

use App\Enums\PcCommandType;
use App\Models\Pc;
use App\Models\PcBooking;
use App\Models\PcCommand;
use App\Models\Session;
use App\Models\SessionBillingLog;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\CreatesTenantApiFixtures;
use Tests\TestCase;

class BillingCommandAndMobilePcEndpointsTest extends TestCase
{
    use CreatesTenantApiFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-04-22 12:00:00'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_billing_logs_support_paginated_and_summary_views(): void
    {
        $fixture = $this->createTenantFixture();

        SessionBillingLog::query()->create([
            'tenant_id' => $fixture['tenant']->id,
            'session_id' => 11,
            'client_id' => $fixture['client']->id,
            'pc_id' => $fixture['pc']->id,
            'mode' => 'wallet',
            'minutes' => 30,
            'amount' => 5000,
            'created_at' => now()->subDay(),
            'updated_at' => now()->subDay(),
        ]);

        SessionBillingLog::query()->create([
            'tenant_id' => $fixture['tenant']->id,
            'session_id' => 11,
            'client_id' => $fixture['client']->id,
            'pc_id' => $fixture['pc']->id,
            'mode' => 'wallet',
            'minutes' => 60,
            'amount' => 9000,
            'created_at' => now()->subDay(),
            'updated_at' => now()->subDay(),
        ]);

        $this->actingAsOwner($fixture['operator'])
            ->getJson('/api/billing-logs?mode=wallet')
            ->assertOk()
            ->assertJsonPath('data.0.mode', 'wallet')
            ->assertJsonPath('total', 2);

        $this->actingAsOwner($fixture['operator'])
            ->getJson('/api/billing-logs?summary=1&mode=wallet')
            ->assertOk()
            ->assertJsonPath('data.0.amount_sum', 14000)
            ->assertJsonPath('data.0.minutes_sum', 90)
            ->assertJsonPath('data.0.cnt', 2);
    }

    public function test_pc_command_send_creates_message_and_rejects_busy_shutdown(): void
    {
        $fixture = $this->createTenantFixture();

        $messageResponse = $this->actingAsOwner($fixture['operator'])
            ->postJson('/api/pcs/' . $fixture['pc']->id . '/commands', [
                'type' => PcCommandType::Message->value,
                'payload' => ['text' => 'Hello PC'],
                'batch_id' => 'batch-1',
            ]);

        $messageResponse->assertCreated();

        $commandId = (int) $messageResponse->json('data.id');

        $this->assertGreaterThan(0, $commandId);

        $this->assertDatabaseHas('pc_commands', [
            'id' => $commandId,
            'tenant_id' => $fixture['tenant']->id,
            'pc_id' => $fixture['pc']->id,
            'type' => PcCommandType::Message->value,
            'batch_id' => 'batch-1',
            'status' => 'pending',
        ]);

        $fixture['pc']->update(['status' => 'busy']);
        Session::query()->create([
            'tenant_id' => $fixture['tenant']->id,
            'pc_id' => $fixture['pc']->id,
            'client_id' => $fixture['client']->id,
            'operator_id' => $fixture['operator']->id,
            'started_at' => now()->subMinutes(20),
            'status' => 'active',
            'price_total' => 0,
        ]);

        $this->actingAsOwner($fixture['operator'])
            ->postJson('/api/pcs/' . $fixture['pc']->id . '/commands', [
                'type' => PcCommandType::Shutdown->value,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['type']);
    }

    public function test_mobile_party_book_and_quick_rebook_use_request_services(): void
    {
        $fixture = $this->createTenantFixture();
        $token = $this->issueClientToken($fixture['tenant'], $fixture['client']);

        $secondPc = Pc::query()->create([
            'tenant_id' => $fixture['tenant']->id,
            'code' => 'PC-2',
            'zone_id' => $fixture['zone']->id,
            'zone' => 'VIP',
            'status' => 'online',
            'last_seen_at' => now(),
            'is_hidden' => false,
        ]);

        $this->withHeaders($this->clientHeaders($token))
            ->postJson('/api/mobile/pcs/party-book', [
                'pc_ids' => [$fixture['pc']->id, $secondPc->id],
                'hold_minutes' => 45,
            ])
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('reserved_count', 2);

        $this->assertDatabaseHas('pc_bookings', [
            'tenant_id' => $fixture['tenant']->id,
            'pc_id' => $fixture['pc']->id,
            'client_id' => $fixture['client']->id,
        ]);
        $this->assertDatabaseHas('pc_bookings', [
            'tenant_id' => $fixture['tenant']->id,
            'pc_id' => $secondPc->id,
            'client_id' => $fixture['client']->id,
        ]);

        PcBooking::query()->where('tenant_id', $fixture['tenant']->id)->delete();

        Session::query()->create([
            'tenant_id' => $fixture['tenant']->id,
            'pc_id' => $secondPc->id,
            'client_id' => $fixture['client']->id,
            'operator_id' => $fixture['operator']->id,
            'started_at' => now()->subDays(2),
            'ended_at' => now()->subDays(2)->addHour(),
            'status' => 'finished',
            'price_total' => 10000,
        ]);

        $this->withHeaders($this->clientHeaders($token))
            ->postJson('/api/mobile/pcs/rebook-quick', [
                'hold_minutes' => 30,
            ])
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('source_pc_id', $secondPc->id)
            ->assertJsonPath('pc.id', $secondPc->id);
    }

    public function test_mobile_smart_seat_hold_and_queue_join_roundtrip(): void
    {
        $fixture = $this->createTenantFixture();
        $token = $this->issueClientToken($fixture['tenant'], $fixture['client']);
        $zoneKey = 'id:' . $fixture['zone']->id;

        $this->withHeaders($this->clientHeaders($token))
            ->getJson('/api/mobile/pcs/smart-seat?zone_key=' . urlencode($zoneKey) . '&arrive_in=15&limit=1')
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('items.0.pc_id', $fixture['pc']->id);

        $this->withHeaders($this->clientHeaders($token))
            ->postJson('/api/mobile/pcs/smart-seat/hold', [
                'pc_id' => $fixture['pc']->id,
                'hold_minutes' => 15,
            ])
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('pc.id', $fixture['pc']->id);

        PcBooking::query()->where('tenant_id', $fixture['tenant']->id)->delete();

        $this->withHeaders($this->clientHeaders($token))
            ->postJson('/api/mobile/client/smart-queue/join', [
                'zone_key' => $zoneKey,
                'notify_on_free' => true,
            ])
            ->assertOk()
            ->assertJsonPath('ok', true);

        $this->withHeaders($this->clientHeaders($token))
            ->getJson('/api/mobile/client/smart-queue')
            ->assertOk()
            ->assertJsonPath('items.0.zone_key', $zoneKey)
            ->assertJsonPath('items.0.ready_now', true)
            ->assertJsonPath('notifications.0.type', 'smart_queue_ready');
    }

    private function clientHeaders(string $token): array
    {
        return [
            'Authorization' => 'Bearer ' . $token,
        ];
    }
}
