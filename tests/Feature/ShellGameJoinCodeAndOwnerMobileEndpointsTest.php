<?php

namespace Tests\Feature;

use App\Models\ClientGameProfile;
use App\Models\LicenseKey;
use App\Models\Operator;
use App\Models\PcShellGame;
use App\Models\ShellGame;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\Feature\Concerns\CreatesTenantApiFixtures;
use Tests\TestCase;

class ShellGameJoinCodeAndOwnerMobileEndpointsTest extends TestCase
{
    use CreatesTenantApiFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-04-22 17:00:00'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_public_shell_games_boot_default_catalog_and_merge_profile_state(): void
    {
        $fixture = $this->createTenantFixture();
        $token = $this->issueClientToken($fixture['tenant'], $fixture['client']);

        $response = $this->withHeaders($this->clientHeaders($token))
            ->getJson('/api/shell/games?pc_code=' . $fixture['pc']->code);

        $response
            ->assertOk()
            ->assertJsonPath('meta.pc_found', true);

        $cs2 = ShellGame::query()
            ->where('tenant_id', $fixture['tenant']->id)
            ->where('slug', 'cs2')
            ->firstOrFail();

        PcShellGame::query()->create([
            'tenant_id' => $fixture['tenant']->id,
            'pc_id' => $fixture['pc']->id,
            'shell_game_id' => $cs2->id,
            'is_installed' => true,
            'version' => '1.0.0',
            'last_seen_at' => now(),
        ]);

        ClientGameProfile::query()->create([
            'tenant_id' => $fixture['tenant']->id,
            'client_id' => $fixture['client']->id,
            'game_slug' => 'cs2',
            'mouse_json' => ['dpi' => 800],
            'archive_path' => '/profiles/cs2.zip',
            'version' => 3,
            'last_synced_at' => now(),
        ]);

        $response = $this->withHeaders($this->clientHeaders($token))
            ->getJson('/api/shell/games?pc_code=' . $fixture['pc']->code);

        $response->assertOk();

        $items = collect($response->json('data'))->keyBy('slug');

        $this->assertTrue($items->has('cs2'));
        $this->assertTrue($items['cs2']['install']['is_installed']);
        $this->assertSame('1.0.0', $items['cs2']['install']['version']);
        $this->assertTrue($items['cs2']['cloud_profile']['exists']);
        $this->assertSame(3, $items['cs2']['cloud_profile']['version']);
        $this->assertSame(800, $items['cs2']['cloud_profile']['mouse_profile']['dpi']);
        $this->assertNotEmpty($items['cs2']['cloud_profile']['config_paths']);
    }

    public function test_admin_shell_games_crud_and_pc_state_flow(): void
    {
        $fixture = $this->createTenantFixture();

        $adminIndex = $this->actingAsOwner($fixture['operator'])
            ->getJson('/api/shell-games?pc_id=' . $fixture['pc']->id);

        $adminIndex
            ->assertOk()
            ->assertJsonPath('meta.pc_id', $fixture['pc']->id)
            ->assertJsonPath('meta.pc_found', true);

        $storeResponse = $this->actingAsOwner($fixture['operator'])
            ->postJson('/api/shell-games', [
                'slug' => 'tekken8',
                'title' => 'Tekken 8',
                'category' => 'fighting',
                'launcher' => 'Steam',
                'cloud_profile_enabled' => true,
            ]);

        $gameId = (int) $storeResponse->json('data.id');

        $storeResponse
            ->assertCreated()
            ->assertJsonPath('data.slug', 'tekken8')
            ->assertJsonPath('data.title', 'Tekken 8');

        $this->actingAsOwner($fixture['operator'])
            ->patchJson('/api/shell-games/' . $gameId, [
                'title' => 'Tekken 8 Ultimate',
                'sort_order' => 5,
            ])
            ->assertOk()
            ->assertJsonPath('data.title', 'Tekken 8 Ultimate')
            ->assertJsonPath('data.sort_order', 5);

        $this->actingAsOwner($fixture['operator'])
            ->postJson('/api/pcs/' . $fixture['pc']->id . '/shell-games/' . $gameId . '/state', [
                'is_installed' => true,
                'version' => '8.1',
            ])
            ->assertOk()
            ->assertJsonPath('data.pc_id', $fixture['pc']->id)
            ->assertJsonPath('data.is_installed', true)
            ->assertJsonPath('data.version', '8.1');

        $this->actingAsOwner($fixture['operator'])
            ->postJson('/api/shell-games/' . $gameId . '/toggle')
            ->assertOk()
            ->assertJsonPath('data.id', $gameId)
            ->assertJsonPath('data.is_active', false);
    }

    public function test_tenant_join_code_refresh_returns_active_code_with_expiry(): void
    {
        $fixture = $this->createTenantFixture();

        $response = $this->actingAsOwner($fixture['operator'])
            ->postJson('/api/tenants/join-code/refresh');

        $response
            ->assertOk()
            ->assertJsonPath('data.active', true);

        $joinCode = (string) $response->json('data.join_code');
        $this->assertSame(10, strlen($joinCode));
        $this->assertDatabaseHas('tenants', [
            'id' => $fixture['tenant']->id,
            'join_code' => $joinCode,
            'join_code_active' => true,
        ]);
    }

    public function test_owner_mobile_login_me_logout_and_owner_only_guard(): void
    {
        $fixture = $this->createTenantFixture();
        $license = $this->createLicenseKey($fixture['tenant'], 'LIC-OWNER-01');

        $loginResponse = $this->postJson('/api/owner-mobile/auth/login', [
            'license_key' => $license->key,
            'login' => $fixture['operator']->login,
            'password' => 'secret',
        ]);

        $token = (string) $loginResponse->json('token');

        $loginResponse
            ->assertOk()
            ->assertJsonPath('tenant.id', $fixture['tenant']->id)
            ->assertJsonPath('operator.role', 'owner');

        $this->withHeaders($this->bearer($token))
            ->getJson('/api/owner-mobile/auth/me')
            ->assertOk()
            ->assertJsonPath('operator.id', $fixture['operator']->id)
            ->assertJsonPath('operator.role', 'owner');

        $this->withHeaders($this->bearer($token))
            ->postJson('/api/owner-mobile/auth/logout')
            ->assertOk()
            ->assertJsonPath('ok', true);

        $this->assertDatabaseCount('personal_access_tokens', 0);

        $admin = Operator::query()->create([
            'tenant_id' => $fixture['tenant']->id,
            'name' => 'Admin',
            'login' => 'admin-mobile',
            'password' => Hash::make('secret'),
            'role' => 'admin',
            'is_active' => true,
        ]);

        $this->postJson('/api/owner-mobile/auth/login', [
            'license_key' => $license->key,
            'login' => $admin->login,
            'password' => 'secret',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['login']);
    }

    private function clientHeaders(string $token): array
    {
        return ['Authorization' => 'Bearer ' . $token];
    }

    private function bearer(string $token): array
    {
        return ['Authorization' => 'Bearer ' . $token];
    }
}
