<?php

namespace Tests\Feature;

use App\Models\LicenseKey;
use App\Models\SuperAdmin;
use App\Models\Tenant;
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
        ]);
        Tenant::query()->create([
            'name' => 'Bravo Club',
            'status' => 'suspended',
        ]);
        LicenseKey::query()->create([
            'tenant_id' => $alpha->id,
            'key' => 'LIC-ALPHA-001',
            'status' => 'active',
            'expires_at' => now()->addMonth(),
        ]);

        Sanctum::actingAs($admin, ['*'], 'saas');

        $this->getJson('/api/saas/tenants?status=active&search=Alpha')
            ->assertOk()
            ->assertJsonPath('data.data.0.name', 'Alpha Club')
            ->assertJsonCount(1, 'data.data');

        $storeResponse = $this->postJson('/api/saas/tenants', [
            'name' => 'Charlie Club',
            'status' => 'active',
        ]);

        $tenantId = (int) $storeResponse->json('data.id');

        $storeResponse
            ->assertCreated()
            ->assertJsonPath('data.name', 'Charlie Club')
            ->assertJsonPath('data.status', 'active');

        $this->getJson('/api/saas/tenants/' . $alpha->id)
            ->assertOk()
            ->assertJsonPath('data.id', $alpha->id)
            ->assertJsonPath('data.license_keys_count', 1);

        $this->patchJson('/api/saas/tenants/' . $tenantId, [
            'name' => 'Charlie Club Pro',
            'status' => 'suspended',
        ])
            ->assertOk()
            ->assertJsonPath('data.id', $tenantId)
            ->assertJsonPath('data.name', 'Charlie Club Pro')
            ->assertJsonPath('data.status', 'suspended');
    }

    private function bearer(string $token): array
    {
        return ['Authorization' => 'Bearer ' . $token];
    }
}
