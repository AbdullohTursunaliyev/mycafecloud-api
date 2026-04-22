<?php

namespace Tests\Feature;

use App\Models\MobileFriendship;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MobileAuthAndFriendEndpointsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-04-22 15:00:00'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_mobile_register_and_me_return_thin_service_payload(): void
    {
        $register = $this->postJson('/api/mobile/auth/register', [
            'login' => 'mobile_alpha',
            'password' => 'secret123',
            'password_confirmation' => 'secret123',
        ]);

        $register
            ->assertCreated()
            ->assertJsonPath('user.login', 'mobile_alpha')
            ->assertJsonPath('clubs', []);

        $token = (string) $register->json('token');

        $this->withHeaders([
            'Authorization' => 'Bearer ' . $token,
        ])->getJson('/api/mobile/auth/me')
            ->assertOk()
            ->assertJsonPath('user.login', 'mobile_alpha')
            ->assertJsonPath('clubs', []);
    }

    public function test_mobile_friend_request_flow_works_through_refactored_controller(): void
    {
        $aliceToken = $this->registerMobileUser('alice_mobile');
        $bobToken = $this->registerMobileUser('bob_mobile');

        $bobMe = $this->withHeaders([
            'Authorization' => 'Bearer ' . $bobToken,
        ])->getJson('/api/mobile/auth/me');

        $bobId = (int) $bobMe->json('user.id');

        $this->withHeaders([
            'Authorization' => 'Bearer ' . $aliceToken,
        ])->postJson('/api/mobile/friends/requests', [
            'friend_mobile_user_id' => $bobId,
        ])
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('status', 'pending');

        $friendshipId = (int) MobileFriendship::query()->value('id');

        $this->withHeaders([
            'Authorization' => 'Bearer ' . $bobToken,
        ])->getJson('/api/mobile/friends')
            ->assertOk()
            ->assertJsonCount(1, 'incoming');

        $this->withHeaders([
            'Authorization' => 'Bearer ' . $bobToken,
        ])->postJson('/api/mobile/friends/requests/' . $friendshipId . '/respond', [
            'action' => 'accept',
        ])
            ->assertOk()
            ->assertJsonPath('status', 'accepted');

        $this->withHeaders([
            'Authorization' => 'Bearer ' . $aliceToken,
        ])->getJson('/api/mobile/friends')
            ->assertOk()
            ->assertJsonCount(1, 'friends')
            ->assertJsonPath('friends.0.status', 'accepted');
    }

    private function registerMobileUser(string $login): string
    {
        $response = $this->postJson('/api/mobile/auth/register', [
            'login' => $login,
            'password' => 'secret123',
            'password_confirmation' => 'secret123',
        ]);

        $response->assertCreated();

        return (string) $response->json('token');
    }
}
