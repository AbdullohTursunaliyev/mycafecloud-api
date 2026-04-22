<?php

namespace Tests\Feature;

use App\Enums\PaymentMethod;
use App\Models\Client;
use App\Models\ClientPackage;
use App\Models\ClientTransfer;
use App\Models\Package;
use App\Models\PackageSale;
use App\Models\Shift;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\Feature\Concerns\CreatesTenantApiFixtures;
use Tests\TestCase;

class PackageAndTransferEndpointsTest extends TestCase
{
    use CreatesTenantApiFixtures;
    use RefreshDatabase;

    public function test_package_attach_with_balance_creates_client_package_and_charge(): void
    {
        $fixture = $this->createTenantFixture();
        $fixture['client']->update(['balance' => 80000]);

        $package = Package::query()->create([
            'tenant_id' => $fixture['tenant']->id,
            'name' => 'VIP 5 hours',
            'duration_min' => 300,
            'price' => 50000,
            'zone' => 'VIP',
            'is_active' => true,
        ]);

        $response = $this->actingAsOwner($fixture['operator'])
            ->postJson('/api/clients/' . $fixture['client']->id . '/packages/attach', [
                'package_id' => $package->id,
                'payment_method' => PaymentMethod::Balance->value,
            ]);

        $response
            ->assertOk()
            ->assertJsonPath('data.package.id', $package->id)
            ->assertJsonPath('data.payment_method', PaymentMethod::Balance->value)
            ->assertJsonPath('data.amount', 50000)
            ->assertJsonPath('data.shift_id', null);

        $clientPackage = ClientPackage::query()->firstOrFail();
        $sale = PackageSale::query()->firstOrFail();

        $this->assertSame(300, (int) $clientPackage->remaining_min);
        $this->assertSame('active', (string) $clientPackage->status);
        $this->assertSame(30000, (int) $fixture['client']->fresh()->balance);
        $this->assertSame($clientPackage->id, (int) ($sale->meta['client_package_id'] ?? 0));
        $this->assertDatabaseHas('client_transactions', [
            'tenant_id' => $fixture['tenant']->id,
            'client_id' => $fixture['client']->id,
            'type' => 'package',
            'amount' => 50000,
            'payment_method' => PaymentMethod::Balance->value,
            'shift_id' => null,
        ]);
    }

    public function test_package_attach_with_cash_uses_open_shift_and_updates_shift_totals(): void
    {
        $fixture = $this->createTenantFixture();

        $shift = Shift::query()->create([
            'tenant_id' => $fixture['tenant']->id,
            'opened_by_operator_id' => $fixture['operator']->id,
            'opened_at' => now(),
            'opening_cash' => 100000,
            'packages_cash_total' => 10000,
            'status' => 'open',
        ]);

        $package = Package::query()->create([
            'tenant_id' => $fixture['tenant']->id,
            'name' => 'VIP Sprint',
            'duration_min' => 120,
            'price' => 25000,
            'zone' => 'VIP',
            'is_active' => true,
        ]);

        $this->actingAsOwner($fixture['operator'])
            ->postJson('/api/clients/' . $fixture['client']->id . '/packages/attach', [
                'package_id' => $package->id,
                'payment_method' => PaymentMethod::Cash->value,
            ])
            ->assertOk()
            ->assertJsonPath('data.shift_id', $shift->id);

        $this->assertSame(35000, (int) $shift->fresh()->packages_cash_total);
        $this->assertDatabaseHas('package_sales', [
            'tenant_id' => $fixture['tenant']->id,
            'client_id' => $fixture['client']->id,
            'package_id' => $package->id,
            'payment_method' => PaymentMethod::Cash->value,
            'shift_id' => $shift->id,
        ]);
    }

    public function test_package_attach_rejects_second_active_package_on_same_zone(): void
    {
        $fixture = $this->createTenantFixture();

        $package = Package::query()->create([
            'tenant_id' => $fixture['tenant']->id,
            'name' => 'VIP Long',
            'duration_min' => 240,
            'price' => 45000,
            'zone' => 'VIP',
            'is_active' => true,
        ]);

        ClientPackage::query()->create([
            'tenant_id' => $fixture['tenant']->id,
            'client_id' => $fixture['client']->id,
            'package_id' => $package->id,
            'remaining_min' => 120,
            'expires_at' => now()->addDay(),
            'status' => 'active',
        ]);

        $this->actingAsOwner($fixture['operator'])
            ->postJson('/api/clients/' . $fixture['client']->id . '/packages/attach', [
                'package_id' => $package->id,
                'payment_method' => PaymentMethod::Balance->value,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['package_id']);
    }

    public function test_transfer_moves_balance_and_creates_audit_rows(): void
    {
        $fixture = $this->createTenantFixture();
        $receiver = Client::query()->create([
            'tenant_id' => $fixture['tenant']->id,
            'account_id' => 'CL-2',
            'login' => 'client2',
            'password' => Hash::make('secret'),
            'balance' => 10000,
            'bonus' => 0,
            'status' => 'active',
        ]);

        $shift = Shift::query()->create([
            'tenant_id' => $fixture['tenant']->id,
            'opened_by_operator_id' => $fixture['operator']->id,
            'opened_at' => now(),
            'opening_cash' => 50000,
            'status' => 'open',
        ]);

        $fixture['client']->update(['balance' => 70000]);

        $response = $this->actingAsOwner($fixture['operator'])
            ->postJson('/api/clients/' . $fixture['client']->id . '/transfer', [
                'to_client_id' => $receiver->id,
                'amount' => 25000,
            ]);

        $response
            ->assertCreated()
            ->assertJsonPath('data.transfer.shift_id', $shift->id)
            ->assertJsonPath('data.sender.balance', 45000)
            ->assertJsonPath('data.receiver.balance', 35000);

        $transfer = ClientTransfer::query()->firstOrFail();
        $this->assertSame(25000, (int) $transfer->amount);
        $this->assertDatabaseHas('client_transactions', [
            'tenant_id' => $fixture['tenant']->id,
            'client_id' => $fixture['client']->id,
            'type' => 'transfer_out',
            'amount' => -25000,
            'payment_method' => PaymentMethod::Balance->value,
        ]);
        $this->assertDatabaseHas('client_transactions', [
            'tenant_id' => $fixture['tenant']->id,
            'client_id' => $receiver->id,
            'type' => 'transfer_in',
            'amount' => 25000,
            'payment_method' => PaymentMethod::Balance->value,
        ]);
    }

    public function test_transfer_rejects_same_client_target(): void
    {
        $fixture = $this->createTenantFixture();

        $this->actingAsOwner($fixture['operator'])
            ->postJson('/api/clients/' . $fixture['client']->id . '/transfer', [
                'to_client_id' => $fixture['client']->id,
                'amount' => 1000,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['to_client_id']);
    }
}
