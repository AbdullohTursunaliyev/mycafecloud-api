<?php

namespace Tests\Feature;

use App\Models\LicenseKey;
use App\Models\Pc;
use App\Models\SaasPlan;
use App\Models\SuperAdmin;
use App\Models\Tenant;
use App\Models\Zone;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Concerns\CreatesTenantApiFixtures;
use Tests\TestCase;

class OperatorCpAndSaasTenantEndpointsTest extends TestCase
{
    use CreatesTenantApiFixtures;
    use RefreshDatabase;

    public function test_operator_auth_login_me_and_logout_flow(): void
    {
        $fixture = $this->createTenantFixture();
        $license = $this->createLicenseKey($fixture['tenant'], 'LIC-OP-001');

        $loginResponse = $this->postJson('/api/auth/login', [
            'license_key' => $license->key,
            'login' => $fixture['operator']->login,
            'password' => 'secret',
        ]);

        $token = (string) $loginResponse->json('token');

        $loginResponse
            ->assertOk()
            ->assertJsonPath('tenant.id', $fixture['tenant']->id)
            ->assertJsonPath('operator.id', $fixture['operator']->id)
            ->assertJsonPath('operator.role', $fixture['operator']->role);

        $this->withHeaders($this->bearer($token))
            ->getJson('/api/auth/me')
            ->assertOk()
            ->assertJsonPath('operator.id', $fixture['operator']->id)
            ->assertJsonPath('operator.login', $fixture['operator']->login)
            ->assertJsonPath('operator.tenant_id', $fixture['tenant']->id);

        $this->withHeaders($this->bearer($token))
            ->postJson('/api/auth/logout')
            ->assertOk()
            ->assertJsonPath('ok', true);

        $this->assertDatabaseMissing('personal_access_tokens', [
            'tokenable_type' => 'App\\Models\\Operator',
            'tokenable_id' => $fixture['operator']->id,
        ]);

        $this->assertNotNull(
            LicenseKey::query()->findOrFail($license->id)->last_used_at
        );
    }

    public function test_cp_auth_login_me_and_logout_flow(): void
    {
        $fixture = $this->createTenantFixture();
        $license = $this->createLicenseKey($fixture['tenant'], 'LIC-CP-001');

        $loginResponse = $this->postJson('/api/cp/auth/login', [
            'license_key' => $license->key,
            'login' => $fixture['operator']->login,
            'password' => 'secret',
        ]);

        $token = (string) $loginResponse->json('token');

        $loginResponse
            ->assertOk()
            ->assertJsonPath('tenant.id', $fixture['tenant']->id)
            ->assertJsonPath('operator.login', $fixture['operator']->login)
            ->assertJsonPath('operator.is_active', true);

        $this->withHeaders($this->bearer($token))
            ->getJson('/api/cp/auth/me')
            ->assertOk()
            ->assertJsonPath('tenant.id', $fixture['tenant']->id)
            ->assertJsonPath('operator.id', $fixture['operator']->id)
            ->assertJsonPath('operator.role', $fixture['operator']->role);

        $this->withHeaders($this->bearer($token))
            ->postJson('/api/cp/auth/logout')
            ->assertOk()
            ->assertJsonPath('ok', true);

        $this->assertDatabaseMissing('personal_access_tokens', [
            'tokenable_type' => 'App\\Models\\Operator',
            'tokenable_id' => $fixture['operator']->id,
        ]);
    }

    public function test_cp_auth_rejects_disabled_operator(): void
    {
        $fixture = $this->createTenantFixture();
        $license = $this->createLicenseKey($fixture['tenant'], 'LIC-CP-002');
        $fixture['operator']->forceFill(['is_active' => false])->save();

        $this->postJson('/api/cp/auth/login', [
            'license_key' => $license->key,
            'login' => $fixture['operator']->login,
            'password' => 'secret',
        ])
            ->assertStatus(403)
            ->assertJsonPath('message', 'Аккаунт отключён');
    }

    public function test_saas_tenant_index_filters_and_crud_flow(): void
    {
        $admin = SuperAdmin::query()->create([
            'name' => 'SaaS Admin',
            'email' => 'saas@example.test',
            'password' => Hash::make('secret'),
            'is_active' => true,
        ]);

        $alpha = Tenant::query()->create([
            'name' => 'Alpha Club',
            'status' => 'active',
            'saas_plan_id' => SaasPlan::query()->where('code', 'basic')->value('id'),
        ]);
        Tenant::query()->create([
            'name' => 'Bravo Club',
            'status' => 'suspended',
            'saas_plan_id' => SaasPlan::query()->where('code', 'pro')->value('id'),
        ]);
        LicenseKey::query()->create([
            'tenant_id' => $alpha->id,
            'key' => 'LIC-ALPHA-001',
            'status' => 'active',
            'expires_at' => now()->addMonth(),
        ]);
        $zone = Zone::query()->create([
            'tenant_id' => $alpha->id,
            'name' => 'Alpha Zone',
            'price_per_hour' => 10000,
            'is_active' => true,
        ]);
        Pc::query()->create([
            'tenant_id' => $alpha->id,
            'code' => 'PC-ALPHA-1',
            'zone_id' => $zone->id,
            'zone' => 'Alpha Zone',
            'status' => 'online',
            'last_seen_at' => now(),
            'is_hidden' => false,
        ]);

        Sanctum::actingAs($admin, ['*'], 'saas');

        $this->getJson('/api/saas/tenants?status=active&search=Alpha&plan_code=basic')
            ->assertOk()
            ->assertJsonPath('data.data.0.name', 'Alpha Club')
            ->assertJsonPath('data.data.0.saas_plan.code', 'basic')
            ->assertJsonPath('data.data.0.pc_count', 1)
            ->assertJsonPath('data.data.0.saas_plan.pc_count', 1)
            ->assertJsonCount(1, 'data.data');

        $proPlanId = (int) SaasPlan::query()->where('code', 'pro')->value('id');
        $storeResponse = $this->postJson('/api/saas/tenants', [
            'name' => 'Charlie Club',
            'status' => 'active',
            'saas_plan_id' => $proPlanId,
        ]);

        $tenantId = (int) $storeResponse->json('data.id');

        $storeResponse
            ->assertCreated()
            ->assertJsonPath('data.name', 'Charlie Club')
            ->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.saas_plan.code', 'pro');

        $this->getJson('/api/saas/tenants/' . $alpha->id)
            ->assertOk()
            ->assertJsonPath('data.id', $alpha->id)
            ->assertJsonPath('data.license_keys_count', 1)
            ->assertJsonPath('data.saas_plan.code', 'basic')
            ->assertJsonPath('data.saas_plan.estimated_monthly_price_uzs', 0);

        $basicPlanId = (int) SaasPlan::query()->where('code', 'basic')->value('id');

        $this->patchJson('/api/saas/tenants/' . $tenantId, [
            'name' => 'Charlie Club Pro',
            'status' => 'suspended',
            'saas_plan_id' => $basicPlanId,
        ])
            ->assertOk()
            ->assertJsonPath('data.id', $tenantId)
            ->assertJsonPath('data.name', 'Charlie Club Pro')
            ->assertJsonPath('data.status', 'suspended')
            ->assertJsonPath('data.saas_plan.code', 'basic');
    }

    public function test_saas_plan_index_and_update_flow(): void
    {
        $admin = SuperAdmin::query()->create([
            'name' => 'SaaS Admin',
            'email' => 'plans@example.test',
            'password' => Hash::make('secret'),
            'is_active' => true,
        ]);

        Sanctum::actingAs($admin, ['*'], 'saas');

        $plansResponse = $this->getJson('/api/saas/plans')
            ->assertOk()
            ->assertJsonCount(2, 'data');

        $basicId = (int) collect($plansResponse->json('data'))
            ->firstWhere('code', 'basic')['id'];

        $this->patchJson('/api/saas/plans/' . $basicId, [
            'name' => 'Basic Lite',
            'price_per_pc_uzs' => 12000,
            'features' => [
                'nexora_ai' => false,
                'ai_generation' => false,
                'ai_insights' => false,
                'ai_autopilot' => false,
            ],
        ])
            ->assertOk()
            ->assertJsonPath('data.code', 'basic')
            ->assertJsonPath('data.name', 'Basic Lite')
            ->assertJsonPath('data.price_per_pc_uzs', 12000)
            ->assertJsonPath('data.features.nexora_ai', false);
    }

    public function test_saas_reports_overview_returns_mrr_growth_and_pc_signals(): void
    {
        $admin = SuperAdmin::query()->create([
            'name' => 'SaaS Admin',
            'email' => 'reports@example.test',
            'password' => Hash::make('secret'),
            'is_active' => true,
        ]);

        $basicPlan = SaasPlan::query()->where('code', 'basic')->firstOrFail();
        $proPlan = SaasPlan::query()->where('code', 'pro')->firstOrFail();

        $basicPlan->forceFill(['price_per_pc_uzs' => 10000])->save();
        $proPlan->forceFill(['price_per_pc_uzs' => 20000])->save();

        $oldTenant = Tenant::query()->create([
            'name' => 'Old Basic Club',
            'status' => 'active',
            'saas_plan_id' => $basicPlan->id,
        ]);
        $oldTenant->forceFill(['created_at' => now()->startOfMonth()->subDays(10)])->save();

        $newTenant = Tenant::query()->create([
            'name' => 'New Pro Club',
            'status' => 'active',
            'saas_plan_id' => $proPlan->id,
        ]);
        $newTenant->forceFill(['created_at' => now()->startOfMonth()->addDays(2)])->save();

        $oldZone = Zone::query()->create([
            'tenant_id' => $oldTenant->id,
            'name' => 'Old Zone',
            'price_per_hour' => 10000,
            'is_active' => true,
        ]);
        $newZone = Zone::query()->create([
            'tenant_id' => $newTenant->id,
            'name' => 'New Zone',
            'price_per_hour' => 20000,
            'is_active' => true,
        ]);

        Pc::query()->create([
            'tenant_id' => $oldTenant->id,
            'code' => 'OLD-PC-1',
            'zone_id' => $oldZone->id,
            'zone' => 'Old Zone',
            'status' => 'online',
            'last_seen_at' => now(),
            'is_hidden' => false,
        ]);
        Pc::query()->create([
            'tenant_id' => $oldTenant->id,
            'code' => 'OLD-PC-2',
            'zone_id' => $oldZone->id,
            'zone' => 'Old Zone',
            'status' => 'online',
            'last_seen_at' => now(),
            'is_hidden' => false,
        ]);
        Pc::query()->create([
            'tenant_id' => $newTenant->id,
            'code' => 'NEW-PC-1',
            'zone_id' => $newZone->id,
            'zone' => 'New Zone',
            'status' => 'online',
            'last_seen_at' => now(),
            'is_hidden' => false,
        ]);
        Pc::query()->create([
            'tenant_id' => $newTenant->id,
            'code' => 'NEW-PC-2',
            'zone_id' => $newZone->id,
            'zone' => 'New Zone',
            'status' => 'online',
            'last_seen_at' => now(),
            'is_hidden' => false,
        ]);
        Pc::query()->create([
            'tenant_id' => $newTenant->id,
            'code' => 'NEW-PC-3',
            'zone_id' => $newZone->id,
            'zone' => 'New Zone',
            'status' => 'offline',
            'last_seen_at' => now(),
            'is_hidden' => false,
        ]);

        Sanctum::actingAs($admin, ['*'], 'saas');

        $this->getJson('/api/saas/reports/overview')
            ->assertOk()
            ->assertJsonPath('data.metrics.current_mrr_uzs', 80000)
            ->assertJsonPath('data.metrics.previous_mrr_uzs', 20000)
            ->assertJsonPath('data.metrics.mrr_growth_uzs', 60000)
            ->assertJsonPath('data.metrics.mrr_growth_percent', 300)
            ->assertJsonPath('data.metrics.connected_pcs', 5)
            ->assertJsonPath('data.metrics.online_pcs', 4)
            ->assertJsonPath('data.metrics.active_tenants', 2)
            ->assertJsonPath('data.metrics.basic_tenants', 1)
            ->assertJsonPath('data.metrics.pro_tenants', 1)
            ->assertJsonPath('data.metrics.this_month_new_tenants', 1)
            ->assertJsonPath('data.metrics.this_month_new_mrr_uzs', 60000)
            ->assertJsonPath('data.plan_mix.0.code', 'basic')
            ->assertJsonPath('data.plan_mix.0.connected_pcs', 2)
            ->assertJsonPath('data.plan_mix.1.code', 'pro')
            ->assertJsonPath('data.plan_mix.1.mrr_uzs', 60000)
            ->assertJsonCount(6, 'data.trend');
    }

    private function bearer(string $token): array
    {
        return ['Authorization' => 'Bearer ' . $token];
    }
}
