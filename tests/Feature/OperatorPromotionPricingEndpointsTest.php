<?php

namespace Tests\Feature;

use App\Models\Operator;
use App\Models\Package;
use App\Models\Promotion;
use App\Models\SubscriptionPlan;
use App\Models\Tenant;
use App\Models\Zone;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\Feature\Concerns\CreatesTenantApiFixtures;
use Tests\TestCase;

class OperatorPromotionPricingEndpointsTest extends TestCase
{
    use CreatesTenantApiFixtures;
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_operator_store_and_update_flow_uses_service_and_resource(): void
    {
        $fixture = $this->createTenantFixture();

        $storeResponse = $this->actingAsOwner($fixture['operator'])
            ->postJson('/api/operators', [
                'name' => 'Cashier',
                'login' => 'cashier-1',
                'password' => 'secret2',
                'role' => 'admin',
            ]);

        $storeResponse
            ->assertCreated()
            ->assertJsonPath('data.login', 'cashier-1')
            ->assertJsonPath('data.role', 'admin')
            ->assertJsonMissingPath('data.password');

        $operatorId = (int) $storeResponse->json('data.id');

        $this->actingAsOwner($fixture['operator'])
            ->patchJson('/api/operators/' . $operatorId, [
                'name' => 'Cashier Updated',
                'password' => 'new-secret',
                'is_active' => false,
            ])
            ->assertOk()
            ->assertJsonPath('data.name', 'Cashier Updated')
            ->assertJsonPath('data.is_active', false);

        $created = Operator::query()->findOrFail($operatorId);

        $this->assertTrue(Hash::check('new-secret', (string) $created->password));
    }

    public function test_package_catalog_store_filter_and_toggle_flow(): void
    {
        $fixture = $this->createTenantFixture();

        Package::query()->create([
            'tenant_id' => $fixture['tenant']->id,
            'name' => 'Standard Bundle',
            'duration_min' => 120,
            'price' => 15000,
            'zone' => 'Standard',
            'is_active' => false,
        ]);

        $storeResponse = $this->actingAsOwner($fixture['operator'])
            ->postJson('/api/packages', [
                'name' => 'VIP Bundle',
                'duration_min' => 300,
                'price' => 50000,
                'zone' => 'VIP',
            ]);

        $packageId = (int) $storeResponse->json('data.id');

        $this->actingAsOwner($fixture['operator'])
            ->getJson('/api/packages?q=VIP&active=1')
            ->assertOk()
            ->assertJsonPath('data.data.0.id', $packageId)
            ->assertJsonPath('data.data.0.zone', 'VIP');

        $this->actingAsOwner($fixture['operator'])
            ->postJson('/api/packages/' . $packageId . '/toggle')
            ->assertOk()
            ->assertJsonPath('data.is_active', false);
    }

    public function test_subscription_plan_endpoints_validate_zone_scope_and_toggle(): void
    {
        $fixture = $this->createTenantFixture();
        $otherTenant = Tenant::query()->create([
            'name' => 'Other Club',
            'status' => 'active',
        ]);
        $otherZone = Zone::query()->create([
            'tenant_id' => $otherTenant->id,
            'name' => 'Foreign',
            'price_per_hour' => 9000,
            'is_active' => true,
        ]);

        $this->actingAsOwner($fixture['operator'])
            ->postJson('/api/subscription-plans', [
                'name' => 'Foreign Plan',
                'zone_id' => $otherZone->id,
                'duration_days' => 30,
                'price' => 99000,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['zone_id']);

        $storeResponse = $this->actingAsOwner($fixture['operator'])
            ->postJson('/api/subscription-plans', [
                'name' => 'VIP Monthly',
                'zone_id' => $fixture['zone']->id,
                'duration_days' => 30,
                'price' => 120000,
            ]);

        $planId = (int) $storeResponse->json('data.id');

        $storeResponse
            ->assertCreated()
            ->assertJsonPath('data.zone.id', $fixture['zone']->id)
            ->assertJsonPath('data.zone.name', 'VIP');

        $this->actingAsOwner($fixture['operator'])
            ->patchJson('/api/subscription-plans/' . $planId, [
                'price' => 130000,
            ])
            ->assertOk()
            ->assertJsonPath('data.price', 130000);

        $this->actingAsOwner($fixture['operator'])
            ->postJson('/api/subscription-plans/' . $planId . '/toggle')
            ->assertOk()
            ->assertJsonPath('data.is_active', false);

        $this->assertDatabaseHas('subscription_plans', [
            'id' => $planId,
            'price' => 130000,
            'is_active' => false,
        ]);
    }

    public function test_promotion_preview_uses_shared_engine_rules(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-04-22 23:30:00'));

        $fixture = $this->createTenantFixture();

        $lowPriority = Promotion::query()->create([
            'tenant_id' => $fixture['tenant']->id,
            'name' => 'Night Bonus Low',
            'type' => 'double_topup',
            'applies_payment_method' => 'cash',
            'priority' => 5,
            'is_active' => true,
            'time_from' => '22:00:00',
            'time_to' => '02:00:00',
        ]);

        $highResponse = $this->actingAsOwner($fixture['operator'])
            ->postJson('/api/promotions', [
                'name' => 'Night Bonus High',
                'type' => 'double_topup',
                'applies_payment_method' => 'cash',
                'priority' => 20,
                'time_from' => '22:00',
                'time_to' => '02:00',
            ]);

        $highResponse->assertCreated()->assertJsonPath('data.priority', 20);
        $highId = (int) $highResponse->json('data.id');

        $this->actingAsOwner($fixture['operator'])
            ->getJson('/api/promotions/active-for-topup?payment_method=cash')
            ->assertOk()
            ->assertJsonPath('data.id', $highId)
            ->assertJsonPath('meta.payment_method', 'cash');

        $this->actingAsOwner($fixture['operator'])
            ->patchJson('/api/promotions/' . $highId, [
                'starts_at' => '2026-04-23',
                'ends_at' => '2026-04-22',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['starts_at']);

        $this->actingAsOwner($fixture['operator'])
            ->postJson('/api/promotions/' . $highId . '/toggle')
            ->assertOk()
            ->assertJsonPath('data.is_active', false);

        $this->actingAsOwner($fixture['operator'])
            ->getJson('/api/promotions/active-for-topup?payment_method=cash')
            ->assertOk()
            ->assertJsonPath('data.id', $lowPriority->id);

        $this->assertDatabaseHas('promotions', [
            'id' => $highId,
            'is_active' => false,
        ]);
    }
}
