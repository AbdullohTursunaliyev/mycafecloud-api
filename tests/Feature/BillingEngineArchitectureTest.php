<?php

namespace Tests\Feature;

use App\Models\Session;
use App\Models\SessionChargeEvent;
use App\Models\ZonePricingWindow;
use App\Services\ClientSessionService;
use App\Services\SessionPauseService;
use App\Services\SessionProjectionService;
use App\Services\SessionResumeService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\CreatesTenantApiFixtures;
use Tests\TestCase;

class BillingEngineArchitectureTest extends TestCase
{
    use CreatesTenantApiFixtures;
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_session_projection_service_returns_rule_and_next_charge_at(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-04-22 12:00:30'));

        $fixture = $this->createTenantFixture();
        $fixture['client']->update(['balance' => 500, 'bonus' => 0]);
        $fixture['pc']->update(['status' => 'busy']);

        $session = Session::query()->create([
            'tenant_id' => $fixture['tenant']->id,
            'pc_id' => $fixture['pc']->id,
            'client_id' => $fixture['client']->id,
            'started_at' => now()->subSeconds(30),
            'status' => 'active',
            'price_total' => 0,
        ]);

        $projection = app(SessionProjectionService::class)->describe($session, $fixture['client']->fresh(), $fixture['pc']->fresh());

        $this->assertSame(90, (int) $projection['seconds_left']);
        $this->assertSame('zone', (string) ($projection['pricing_rule']['type'] ?? ''));
        $this->assertSame(
            Carbon::parse('2026-04-22 12:01:00')->toIso8601String(),
            (string) $projection['next_charge_at'],
        );
        $this->assertFalse((bool) $projection['paused']);
    }

    public function test_dynamic_pricing_window_splits_logout_charge_events(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-04-22 12:31:00'));

        $fixture = $this->createTenantFixture();
        $fixture['client']->update(['balance' => 1000, 'bonus' => 0]);
        $fixture['pc']->update(['status' => 'busy']);

        ZonePricingWindow::query()->create([
            'tenant_id' => $fixture['tenant']->id,
            'zone_id' => $fixture['zone']->id,
            'name' => 'Prime Time',
            'starts_at' => '12:30:00',
            'ends_at' => '13:30:00',
            'weekdays' => null,
            'price_per_hour' => 12000,
            'is_active' => true,
        ]);

        $session = Session::query()->create([
            'tenant_id' => $fixture['tenant']->id,
            'pc_id' => $fixture['pc']->id,
            'client_id' => $fixture['client']->id,
            'started_at' => Carbon::parse('2026-04-22 12:29:00'),
            'status' => 'active',
            'price_total' => 0,
        ]);

        app(ClientSessionService::class)->logoutClientFromPc(
            (int) $fixture['tenant']->id,
            $fixture['pc']->fresh(),
            $fixture['client']->fresh(),
            now(),
        );

        $session->refresh();
        $events = SessionChargeEvent::query()
            ->where('session_id', $session->id)
            ->orderBy('id')
            ->get();

        $this->assertSame('finished', (string) $session->status);
        $this->assertSame(367, (int) $session->price_total);
        $this->assertSame(633, (int) $fixture['client']->fresh()->balance);
        $this->assertCount(2, $events);
        $this->assertSame('zone', (string) $events[0]->rule_type);
        $this->assertSame(167, (int) $events[0]->amount);
        $this->assertSame('zone_pricing_window', (string) $events[1]->rule_type);
        $this->assertSame(200, (int) $events[1]->amount);
    }

    public function test_pause_and_resume_freeze_countdown_without_losing_partial_minute(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-04-22 12:00:30'));

        $fixture = $this->createTenantFixture();
        $fixture['client']->update(['balance' => 500, 'bonus' => 0]);
        $fixture['pc']->update(['status' => 'busy']);

        $session = Session::query()->create([
            'tenant_id' => $fixture['tenant']->id,
            'pc_id' => $fixture['pc']->id,
            'client_id' => $fixture['client']->id,
            'started_at' => Carbon::parse('2026-04-22 12:00:00'),
            'status' => 'active',
            'price_total' => 0,
        ]);

        $pauseService = app(SessionPauseService::class);
        $resumeService = app(SessionResumeService::class);
        $projectionService = app(SessionProjectionService::class);

        $paused = $pauseService->pause($session, now());
        $this->assertNotNull($paused->paused_at);

        Carbon::setTestNow(Carbon::parse('2026-04-22 12:05:30'));

        $pausedProjection = $projectionService->describe($paused->fresh(), $fixture['client']->fresh(), $fixture['pc']->fresh());
        $this->assertSame(90, (int) $pausedProjection['seconds_left']);
        $this->assertTrue((bool) $pausedProjection['paused']);
        $this->assertNull($pausedProjection['next_charge_at']);

        $resumed = $resumeService->resume($paused->fresh(), now());
        $resumedProjection = $projectionService->describe($resumed->fresh(), $fixture['client']->fresh(), $fixture['pc']->fresh());

        $this->assertNull($resumed->fresh()->paused_at);
        $this->assertSame(
            Carbon::parse('2026-04-22 12:05:00')->toIso8601String(),
            $resumed->fresh()->started_at?->toIso8601String(),
        );
        $this->assertSame(90, (int) $resumedProjection['seconds_left']);
        $this->assertFalse((bool) $resumedProjection['paused']);
        $this->assertSame(
            Carbon::parse('2026-04-22 12:06:00')->toIso8601String(),
            (string) $resumedProjection['next_charge_at'],
        );
    }

    public function test_dynamic_pricing_window_respects_optional_date_range(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-04-22 18:30:00'));

        $fixture = $this->createTenantFixture();
        $fixture['client']->update(['balance' => 1000, 'bonus' => 0]);
        $fixture['pc']->update(['status' => 'busy']);

        ZonePricingWindow::query()->create([
            'tenant_id' => $fixture['tenant']->id,
            'zone_id' => $fixture['zone']->id,
            'name' => 'Weekend Promo',
            'starts_at' => '18:00:00',
            'ends_at' => '23:00:00',
            'starts_on' => '2026-04-23',
            'ends_on' => '2026-04-25',
            'weekdays' => null,
            'price_per_hour' => 12000,
            'is_active' => true,
        ]);

        $session = Session::query()->create([
            'tenant_id' => $fixture['tenant']->id,
            'pc_id' => $fixture['pc']->id,
            'client_id' => $fixture['client']->id,
            'started_at' => Carbon::parse('2026-04-22 18:00:00'),
            'status' => 'active',
            'price_total' => 0,
        ]);

        $projection = app(SessionProjectionService::class)->describe($session, $fixture['client']->fresh(), $fixture['pc']->fresh());

        $this->assertSame('zone', (string) ($projection['pricing_rule']['type'] ?? ''));
        $this->assertSame((int) $fixture['zone']->price_per_hour, (int) $projection['rate_per_hour']);

        Carbon::setTestNow(Carbon::parse('2026-04-23 18:30:00'));

        $projectionInRange = app(SessionProjectionService::class)->describe($session->fresh(), $fixture['client']->fresh(), $fixture['pc']->fresh());

        $this->assertSame('zone_pricing_window', (string) ($projectionInRange['pricing_rule']['type'] ?? ''));
        $this->assertSame(12000, (int) $projectionInRange['rate_per_hour']);
    }
}
