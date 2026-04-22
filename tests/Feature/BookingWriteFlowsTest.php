<?php

namespace Tests\Feature;

use App\Enums\PcCommandType;
use App\Models\Booking;
use App\Models\Client;
use App\Models\Pc;
use App\Models\Session;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\Feature\Concerns\CreatesTenantApiFixtures;
use Tests\TestCase;

class BookingWriteFlowsTest extends TestCase
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

    public function test_booking_store_creates_active_booking_reserves_pc_and_emits_lock_command(): void
    {
        $fixture = $this->createTenantFixture();

        $response = $this->actingAsOwner($fixture['operator'])
            ->postJson('/api/bookings', [
                'pc_id' => $fixture['pc']->id,
                'client_id' => $fixture['client']->id,
                'start_at' => now()->toIso8601String(),
                'note' => 'Birthday booking',
            ]);

        $response
            ->assertCreated()
            ->assertJsonPath('pc_id', $fixture['pc']->id)
            ->assertJsonPath('client_id', $fixture['client']->id)
            ->assertJsonPath('status', 'active')
            ->assertJsonPath('note', 'Birthday booking');

        $bookingId = (int) $response->json('id');
        $booking = Booking::query()->findOrFail($bookingId);

        $this->assertSame('reserved', (string) $fixture['pc']->fresh()->status);
        $this->assertSame('active', (string) $booking->status);
        $this->assertDatabaseHas('pc_commands', [
            'tenant_id' => $fixture['tenant']->id,
            'pc_id' => $fixture['pc']->id,
            'type' => PcCommandType::Lock->value,
            'status' => 'pending',
        ]);
    }

    public function test_booking_cancel_marks_booking_canceled_and_restores_pc_state(): void
    {
        $fixture = $this->createTenantFixture();

        $booking = Booking::query()->create([
            'tenant_id' => $fixture['tenant']->id,
            'pc_id' => $fixture['pc']->id,
            'client_id' => $fixture['client']->id,
            'created_by_operator_id' => $fixture['operator']->id,
            'start_at' => now()->subMinutes(5),
            'end_at' => now()->addYears(20),
            'status' => 'active',
        ]);

        $fixture['pc']->update([
            'status' => 'reserved',
            'last_seen_at' => now(),
        ]);

        $response = $this->actingAsOwner($fixture['operator'])
            ->postJson('/api/bookings/' . $booking->id . '/cancel');

        $response
            ->assertOk()
            ->assertJsonPath('ok', true);

        $this->assertSame('canceled', (string) $booking->fresh()->status);
        $this->assertSame('online', (string) $fixture['pc']->fresh()->status);
    }

    public function test_booking_store_rejects_when_pc_has_active_session(): void
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

        $response = $this->actingAsOwner($fixture['operator'])
            ->postJson('/api/bookings', [
                'pc_id' => $fixture['pc']->id,
                'client_id' => $fixture['client']->id,
                'start_at' => now()->toIso8601String(),
            ]);

        $response
            ->assertStatus(422)
            ->assertJsonValidationErrors(['pc_id']);
    }

    public function test_booking_store_rejects_when_client_already_has_active_booking(): void
    {
        $fixture = $this->createTenantFixture();

        $otherPc = Pc::query()->create([
            'tenant_id' => $fixture['tenant']->id,
            'code' => 'PC-2',
            'zone_id' => $fixture['zone']->id,
            'zone' => 'VIP',
            'status' => 'online',
            'last_seen_at' => now(),
            'is_hidden' => false,
        ]);

        Booking::query()->create([
            'tenant_id' => $fixture['tenant']->id,
            'pc_id' => $otherPc->id,
            'client_id' => $fixture['client']->id,
            'created_by_operator_id' => $fixture['operator']->id,
            'start_at' => now(),
            'end_at' => now()->addYears(20),
            'status' => 'active',
        ]);

        $response = $this->actingAsOwner($fixture['operator'])
            ->postJson('/api/bookings', [
                'pc_id' => $fixture['pc']->id,
                'client_id' => $fixture['client']->id,
                'start_at' => now()->addMinute()->toIso8601String(),
            ]);

        $response
            ->assertStatus(422)
            ->assertJsonValidationErrors(['client_id']);
    }
}
