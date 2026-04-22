<?php

namespace Tests\Feature;

use App\Enums\PcCommandType;
use App\Models\Client;
use App\Models\PcCommand;
use App\Models\Session;
use App\Models\Setting;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\Feature\Concerns\CreatesTenantApiFixtures;
use Tests\TestCase;

class ClientAuthEndpointsTest extends TestCase
{
    use CreatesTenantApiFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-04-22 11:00:00'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_public_settings_returns_normalized_promo_video_url(): void
    {
        $fixture = $this->createTenantFixture();
        $license = $this->createLicenseKey($fixture['tenant'], 'LIC-CLIENT-01');

        Setting::query()->create([
            'tenant_id' => $fixture['tenant']->id,
            'key' => 'promo_video_url',
            'value' => '/storage/promo/promo.mp4',
        ]);

        $response = $this->getJson('/api/client-auth/settings?license_key=' . $license->key . '&pc_code=' . $fixture['pc']->code);

        $response
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('settings.promo_video_url', 'http://localhost/storage/promo/promo.mp4');
    }

    public function test_public_settings_rejects_invalid_license(): void
    {
        $fixture = $this->createTenantFixture();

        $response = $this->getJson('/api/client-auth/settings?license_key=BAD-LICENSE&pc_code=' . $fixture['pc']->code);

        $response
            ->assertStatus(403)
            ->assertJsonPath('message', 'License invalid');
    }

    public function test_client_auth_login_state_and_me_flow_returns_owned_session_and_command(): void
    {
        $fixture = $this->createTenantFixture();
        $license = $this->createLicenseKey($fixture['tenant'], 'LIC-CLIENT-02');
        $fixture['client']->update(['balance' => 60000]);

        $loginResponse = $this->postJson('/api/client-auth/login', [
            'license_key' => $license->key,
            'pc_code' => $fixture['pc']->code,
            'login' => $fixture['client']->login,
            'password' => 'secret',
        ]);

        $loginResponse
            ->assertOk()
            ->assertJsonPath('client.id', $fixture['client']->id)
            ->assertJsonPath('pc.code', $fixture['pc']->code)
            ->assertJsonPath('session.status', 'active');

        $token = (string) $loginResponse->json('token');
        $this->assertNotSame('', $token);

        $session = Session::query()
            ->where('tenant_id', $fixture['tenant']->id)
            ->where('pc_id', $fixture['pc']->id)
            ->where('client_id', $fixture['client']->id)
            ->where('status', 'active')
            ->latest('id')
            ->firstOrFail();

        PcCommand::query()->create([
            'tenant_id' => $fixture['tenant']->id,
            'pc_id' => $fixture['pc']->id,
            'type' => PcCommandType::Message->value,
            'payload' => ['text' => 'Welcome back'],
            'status' => 'pending',
            'created_at' => now()->addSecond(),
            'updated_at' => now()->addSecond(),
        ]);

        $stateResponse = $this->withHeaders($this->clientHeaders($token))
            ->getJson('/api/client-auth/state?license_key=' . $license->key . '&pc_code=' . $fixture['pc']->code);

        $stateResponse
            ->assertOk()
            ->assertJsonPath('locked', false)
            ->assertJsonPath('session.id', $session->id)
            ->assertJsonPath('command.type', PcCommandType::Message->value)
            ->assertJsonPath('command.payload.text', 'Welcome back');

        $meResponse = $this->withHeaders($this->clientHeaders($token))
            ->getJson('/api/client-auth/me');

        $meResponse
            ->assertOk()
            ->assertJsonPath('data.id', $fixture['client']->id)
            ->assertJsonPath('data.login', $fixture['client']->login);
    }

    public function test_client_auth_logout_revokes_token_and_finishes_owned_session(): void
    {
        $fixture = $this->createTenantFixture();
        $license = $this->createLicenseKey($fixture['tenant'], 'LIC-CLIENT-03');
        $fixture['client']->update(['balance' => 60000]);

        $loginResponse = $this->postJson('/api/client-auth/login', [
            'license_key' => $license->key,
            'pc_code' => $fixture['pc']->code,
            'login' => $fixture['client']->login,
            'password' => 'secret',
        ]);

        $token = (string) $loginResponse->json('token');

        $logoutResponse = $this->withHeaders($this->clientHeaders($token))
            ->postJson('/api/client-auth/logout', [
                'license_key' => $license->key,
                'pc_code' => $fixture['pc']->code,
            ]);

        $logoutResponse
            ->assertOk()
            ->assertJsonPath('ok', true);

        $session = Session::query()
            ->where('tenant_id', $fixture['tenant']->id)
            ->where('pc_id', $fixture['pc']->id)
            ->where('client_id', $fixture['client']->id)
            ->latest('id')
            ->firstOrFail();

        $this->assertSame('finished', (string) $session->status);
        $this->assertNotNull($session->ended_at);
        $this->assertSame('locked', (string) $fixture['pc']->fresh()->status);
        $this->assertDatabaseMissing('client_tokens', [
            'tenant_id' => $fixture['tenant']->id,
            'client_id' => $fixture['client']->id,
        ]);
        $this->assertDatabaseHas('pc_commands', [
            'tenant_id' => $fixture['tenant']->id,
            'pc_id' => $fixture['pc']->id,
            'type' => PcCommandType::Lock->value,
            'status' => 'pending',
        ]);

        $meResponse = $this->withHeaders($this->clientHeaders($token))
            ->getJson('/api/client-auth/me');

        $meResponse->assertStatus(401);
    }

    public function test_client_auth_login_rejects_pc_occupied_by_another_client(): void
    {
        $fixture = $this->createTenantFixture();
        $license = $this->createLicenseKey($fixture['tenant'], 'LIC-CLIENT-04');
        $fixture['client']->update(['balance' => 60000]);

        $otherClient = Client::query()->create([
            'tenant_id' => $fixture['tenant']->id,
            'account_id' => 'CL-2',
            'login' => 'client2',
            'password' => Hash::make('secret'),
            'balance' => 50000,
            'bonus' => 0,
            'status' => 'active',
        ]);

        Session::query()->create([
            'tenant_id' => $fixture['tenant']->id,
            'pc_id' => $fixture['pc']->id,
            'client_id' => $otherClient->id,
            'started_at' => now()->subMinutes(15),
            'status' => 'active',
            'price_total' => 0,
        ]);

        $response = $this->postJson('/api/client-auth/login', [
            'license_key' => $license->key,
            'pc_code' => $fixture['pc']->code,
            'login' => $fixture['client']->login,
            'password' => 'secret',
        ]);

        $response
            ->assertStatus(422)
            ->assertJsonValidationErrors(['pc_code']);
    }

    public function test_client_auth_state_rejects_invalid_token(): void
    {
        $fixture = $this->createTenantFixture();
        $license = $this->createLicenseKey($fixture['tenant'], 'LIC-CLIENT-05');

        $response = $this->withHeaders($this->clientHeaders('invalid-token'))
            ->getJson('/api/client-auth/state?license_key=' . $license->key . '&pc_code=' . $fixture['pc']->code);

        $response
            ->assertStatus(401)
            ->assertJsonPath('message', 'Token invalid');
    }

    public function test_client_auth_logout_rejects_missing_token(): void
    {
        $fixture = $this->createTenantFixture();
        $license = $this->createLicenseKey($fixture['tenant'], 'LIC-CLIENT-06');

        $response = $this->postJson('/api/client-auth/logout', [
            'license_key' => $license->key,
            'pc_code' => $fixture['pc']->code,
        ]);

        $response
            ->assertStatus(401)
            ->assertJsonPath('message', 'No token');
    }

    private function clientHeaders(string $token): array
    {
        return [
            'Authorization' => 'Bearer ' . $token,
        ];
    }
}
