<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\ClientTransaction;
use App\Models\Operator;
use App\Models\Pc;
use App\Models\SaasPlan;
use App\Models\Session;
use App\Models\Setting;
use App\Models\Tenant;
use App\Models\Zone;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ReportEndpointsTest extends TestCase
{
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

    public function test_overview_returns_report_payload_for_owner(): void
    {
        $fixture = $this->createReportFixture();

        $response = $this->actingAsOwner($fixture['operator'])
            ->getJson('/api/reports/overview?from=2026-04-15&to=2026-04-21');

        $response
            ->assertOk()
            ->assertJsonPath('data.tenant.id', $fixture['tenant']->id)
            ->assertJsonPath('data.range.days', 7)
            ->assertJsonPath('data.report.summary.sessions_count', 1)
            ->assertJsonPath('data.report.activity.pcs_total', 1);
    }

    public function test_overview_rejects_date_ranges_longer_than_120_days(): void
    {
        $fixture = $this->createReportFixture();

        $response = $this->actingAsOwner($fixture['operator'])
            ->getJson('/api/reports/overview?from=2025-01-01&to=2025-05-31');

        $response
            ->assertStatus(422)
            ->assertJsonValidationErrors(['from']);
    }

    public function test_owner_mobile_ai_insights_returns_low_utilization_signal(): void
    {
        $fixture = $this->createReportFixture();

        $response = $this->actingAsOwner($fixture['operator'])
            ->getJson('/api/owner-mobile/reports/ai-insights?from=2026-04-15&to=2026-04-21');

        $response
            ->assertOk()
            ->assertJsonPath('data.range.days', 7)
            ->assertJsonPath('data.kpis.gross_sales', 50000);

        $insightIds = collect($response->json('data.insights'))->pluck('id')->all();
        $this->assertContains('low_utilization', $insightIds);
    }

    public function test_basic_plan_cannot_access_owner_mobile_ai_insights(): void
    {
        $fixture = $this->createReportFixture('basic');

        $this->actingAsOwner($fixture['operator'])
            ->getJson('/api/owner-mobile/reports/ai-insights?from=2026-04-15&to=2026-04-21')
            ->assertStatus(403)
            ->assertJsonPath('feature', 'ai_insights')
            ->assertJsonPath('upgrade_required', true)
            ->assertJsonPath('plan.code', 'basic');
    }

    public function test_autopilot_dry_run_does_not_persist_changes(): void
    {
        $fixture = $this->createReportFixture();
        $zone = $fixture['zone'];

        $response = $this->actingAsOwner($fixture['operator'])
            ->postJson('/api/reports/autopilot/apply', [
                'strategy' => 'balanced',
                'dry_run' => true,
                'from' => '2026-04-15',
                'to' => '2026-04-21',
            ]);

        $response
            ->assertOk()
            ->assertJsonPath('data.applied', false)
            ->assertJsonPath('data.dry_run', true);

        $this->assertDatabaseMissing('settings', [
            'tenant_id' => $fixture['tenant']->id,
            'key' => 'beast_mode',
        ]);
        $this->assertSame(10000, $zone->fresh()->price_per_hour);
    }

    public function test_autopilot_apply_persists_beast_mode_when_not_dry_run(): void
    {
        $fixture = $this->createReportFixture();

        $response = $this->actingAsOwner($fixture['operator'])
            ->postJson('/api/reports/autopilot/apply', [
                'strategy' => 'balanced',
                'apply_zone_prices' => false,
                'apply_promotion' => false,
                'enable_beast_mode' => true,
                'from' => '2026-04-15',
                'to' => '2026-04-21',
            ]);

        $response
            ->assertOk()
            ->assertJsonPath('data.applied', true)
            ->assertJsonPath('data.summary.beast_mode_enabled', true)
            ->assertJsonPath('data.beast_mode.enabled', true);

        $beastMode = Setting::query()
            ->where('tenant_id', $fixture['tenant']->id)
            ->where('key', 'beast_mode')
            ->value('value');

        $this->assertIsArray($beastMode);
        $this->assertTrue((bool) ($beastMode['enabled'] ?? false));
        $this->assertSame('balanced', $beastMode['strategy'] ?? null);
    }

    public function test_exchange_config_persists_normalized_configuration(): void
    {
        $fixture = $this->createReportFixture();

        $response = $this->actingAsOwner($fixture['operator'])
            ->postJson('/api/reports/exchange/config', [
                'enabled' => true,
                'radius_km' => 25,
                'min_free_pcs' => 3,
                'referral_bonus_uzs' => 15000,
                'overflow_enabled' => false,
                'auction_floor_uzs' => 25000,
                'auction_ceiling_uzs' => 12000,
            ]);

        $response
            ->assertOk()
            ->assertJsonPath('data.saved', true)
            ->assertJsonPath('data.config.enabled', true)
            ->assertJsonPath('data.config.radius_km', 25)
            ->assertJsonPath('data.config.min_free_pcs', 3)
            ->assertJsonPath('data.config.referral_bonus_uzs', 15000)
            ->assertJsonPath('data.config.overflow_enabled', false)
            ->assertJsonPath('data.config.auction_floor_uzs', 12000)
            ->assertJsonPath('data.config.auction_ceiling_uzs', 25000);

        $saved = Setting::query()
            ->where('tenant_id', $fixture['tenant']->id)
            ->where('key', 'exchange_network')
            ->value('value');

        $this->assertIsArray($saved);
        $this->assertTrue((bool) ($saved['enabled'] ?? false));
        $this->assertSame(12000, $saved['auction_floor_uzs'] ?? null);
        $this->assertSame(25000, $saved['auction_ceiling_uzs'] ?? null);
    }

    private function createReportFixture(string $planCode = 'pro'): array
    {
        $tenant = Tenant::query()->create([
            'name' => 'Test Club',
            'status' => 'active',
            'saas_plan_id' => SaasPlan::query()->where('code', $planCode)->value('id'),
        ]);

        $operator = Operator::query()->create([
            'tenant_id' => $tenant->id,
            'name' => 'Owner',
            'login' => 'owner',
            'password' => Hash::make('secret'),
            'role' => 'owner',
            'is_active' => true,
        ]);

        $zone = Zone::query()->create([
            'tenant_id' => $tenant->id,
            'name' => 'VIP',
            'price_per_hour' => 10000,
            'is_active' => true,
        ]);

        $pc = Pc::query()->create([
            'tenant_id' => $tenant->id,
            'code' => 'PC-1',
            'zone_id' => $zone->id,
            'zone' => 'VIP',
            'status' => 'online',
            'last_seen_at' => now(),
            'is_hidden' => false,
        ]);

        $client = Client::query()->create([
            'tenant_id' => $tenant->id,
            'account_id' => 'CL-1',
            'login' => 'client1',
            'password' => Hash::make('secret'),
            'balance' => 0,
            'bonus' => 0,
            'status' => 'active',
        ]);

        Session::query()->create([
            'tenant_id' => $tenant->id,
            'pc_id' => $pc->id,
            'operator_id' => $operator->id,
            'started_at' => now()->subHour(),
            'ended_at' => now()->subMinutes(20),
            'price_total' => 15000,
            'status' => 'finished',
            'client_id' => $client->id,
        ]);

        ClientTransaction::query()->create([
            'tenant_id' => $tenant->id,
            'client_id' => $client->id,
            'operator_id' => $operator->id,
            'type' => 'topup',
            'amount' => 50000,
            'bonus_amount' => 2000,
            'payment_method' => 'cash',
            'comment' => 'Test topup',
        ]);

        return compact('tenant', 'operator', 'zone');
    }

    private function actingAsOwner(Operator $operator): self
    {
        Sanctum::actingAs($operator, ['*'], 'operator');

        return $this;
    }
}
