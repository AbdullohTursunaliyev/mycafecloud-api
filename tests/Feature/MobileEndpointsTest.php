<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\ClientTransaction;
use App\Models\Event;
use App\Models\Pc;
use App\Models\PcBooking;
use App\Models\Session;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\CreatesTenantApiFixtures;
use Tests\TestCase;

class MobileEndpointsTest extends TestCase
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

    public function test_mobile_client_summary_returns_activity_and_mission_progress(): void
    {
        $fixture = $this->createTenantFixture();

        ClientTransaction::query()->create([
            'tenant_id' => $fixture['tenant']->id,
            'client_id' => $fixture['client']->id,
            'operator_id' => $fixture['operator']->id,
            'type' => 'topup',
            'amount' => 100000,
            'bonus_amount' => 0,
            'payment_method' => 'cash',
            'comment' => 'Summary test topup',
        ]);

        Session::query()->create([
            'tenant_id' => $fixture['tenant']->id,
            'pc_id' => $fixture['pc']->id,
            'operator_id' => $fixture['operator']->id,
            'started_at' => now()->subHours(2),
            'ended_at' => now()->subHour(),
            'price_total' => 15000,
            'status' => 'finished',
            'client_id' => $fixture['client']->id,
        ]);

        $token = $this->issueClientToken($fixture['tenant'], $fixture['client']);

        $response = $this->withHeaders($this->clientHeaders($token))
            ->getJson('/api/mobile/client/summary');

        $response
            ->assertOk()
            ->assertJsonPath('client.id', $fixture['client']->id)
            ->assertJsonPath('leaderboard.position', 1)
            ->assertJsonPath('session_highlights.session_count', 1);

        $missions = collect($response->json('missions.items'))->keyBy('code');

        $this->assertSame(100000, $missions->get('topup_100k')['progress'] ?? null);
        $this->assertTrue((bool) ($missions->get('topup_100k')['can_claim'] ?? false));
    }

    public function test_mobile_client_can_book_and_unbook_pc_with_client_token(): void
    {
        $fixture = $this->createTenantFixture();
        $token = $this->issueClientToken($fixture['tenant'], $fixture['client']);

        $bookResponse = $this->withHeaders($this->clientHeaders($token))
            ->postJson('/api/mobile/pcs/' . $fixture['pc']->id . '/book', [
                'hold_minutes' => 90,
            ]);

        $bookResponse
            ->assertOk()
            ->assertJsonPath('ok', true);

        $this->assertDatabaseHas('pc_bookings', [
            'tenant_id' => $fixture['tenant']->id,
            'pc_id' => $fixture['pc']->id,
            'client_id' => $fixture['client']->id,
        ]);

        $catalogResponse = $this->withHeaders($this->clientHeaders($token))
            ->getJson('/api/mobile/pcs');

        $catalogResponse->assertOk();
        $pc = collect($catalogResponse->json('pcs'))->firstWhere('id', $fixture['pc']->id);

        $this->assertSame('booked', $pc['status'] ?? null);
        $this->assertTrue((bool) ($pc['booking']['is_mine'] ?? false));

        $unbookResponse = $this->withHeaders($this->clientHeaders($token))
            ->deleteJson('/api/mobile/pcs/' . $fixture['pc']->id . '/book');

        $unbookResponse
            ->assertOk()
            ->assertJsonPath('ok', true);

        $this->assertDatabaseMissing('pc_bookings', [
            'tenant_id' => $fixture['tenant']->id,
            'pc_id' => $fixture['pc']->id,
            'client_id' => $fixture['client']->id,
        ]);
    }

    public function test_mobile_catalog_reports_free_busy_and_booked_pcs(): void
    {
        $fixture = $this->createTenantFixture();

        $busyClient = Client::query()->create([
            'tenant_id' => $fixture['tenant']->id,
            'account_id' => 'CL-2',
            'login' => 'client2',
            'password' => bcrypt('secret'),
            'balance' => 0,
            'bonus' => 0,
            'status' => 'active',
        ]);

        $busyPc = Pc::query()->create([
            'tenant_id' => $fixture['tenant']->id,
            'code' => 'PC-2',
            'zone_id' => $fixture['zone']->id,
            'zone' => 'VIP',
            'status' => 'online',
            'last_seen_at' => now(),
            'is_hidden' => false,
        ]);

        $bookedPc = Pc::query()->create([
            'tenant_id' => $fixture['tenant']->id,
            'code' => 'PC-3',
            'zone_id' => $fixture['zone']->id,
            'zone' => 'VIP',
            'status' => 'online',
            'last_seen_at' => now(),
            'is_hidden' => false,
        ]);

        Session::query()->create([
            'tenant_id' => $fixture['tenant']->id,
            'pc_id' => $busyPc->id,
            'operator_id' => $fixture['operator']->id,
            'started_at' => now()->subMinutes(30),
            'price_total' => 0,
            'status' => 'active',
            'client_id' => $busyClient->id,
        ]);

        PcBooking::query()->create([
            'tenant_id' => $fixture['tenant']->id,
            'pc_id' => $bookedPc->id,
            'client_id' => $busyClient->id,
            'reserved_from' => now(),
            'reserved_until' => now()->addHour(),
        ]);

        $token = $this->issueClientToken($fixture['tenant'], $fixture['client']);

        $response = $this->withHeaders($this->clientHeaders($token))
            ->getJson('/api/mobile/pcs');

        $response->assertOk();

        $pcs = collect($response->json('pcs'))->keyBy('code');

        $this->assertSame('free', $pcs->get('PC-1')['status'] ?? null);
        $this->assertSame('busy', $pcs->get('PC-2')['status'] ?? null);
        $this->assertSame('booked', $pcs->get('PC-3')['status'] ?? null);
    }

    public function test_mobile_client_can_claim_completed_topup_mission(): void
    {
        $fixture = $this->createTenantFixture();

        ClientTransaction::query()->create([
            'tenant_id' => $fixture['tenant']->id,
            'client_id' => $fixture['client']->id,
            'operator_id' => $fixture['operator']->id,
            'type' => 'topup',
            'amount' => 100000,
            'bonus_amount' => 0,
            'payment_method' => 'cash',
            'comment' => 'Mission claim topup',
        ]);

        $token = $this->issueClientToken($fixture['tenant'], $fixture['client']);

        $response = $this->withHeaders($this->clientHeaders($token))
            ->postJson('/api/mobile/client/missions/topup_100k/claim');

        $response
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('code', 'topup_100k')
            ->assertJsonPath('reward_bonus', 5000)
            ->assertJsonPath('client_bonus', 5000);

        $this->assertSame(5000, (int) $fixture['client']->fresh()->bonus);
        $this->assertDatabaseHas('events', [
            'tenant_id' => $fixture['tenant']->id,
            'type' => 'mobile_mission_claim',
            'entity_type' => 'client',
            'entity_id' => $fixture['client']->id,
        ]);
    }

    private function clientHeaders(string $token): array
    {
        return [
            'Authorization' => 'Bearer ' . $token,
        ];
    }
}
