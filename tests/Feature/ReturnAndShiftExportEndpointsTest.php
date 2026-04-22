<?php

namespace Tests\Feature;

use App\Enums\PaymentMethod;
use App\Models\ClientTransaction;
use App\Models\PackageSale;
use App\Models\ReturnRecord;
use App\Models\Shift;
use App\Models\ShiftExpense;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\CreatesTenantApiFixtures;
use Tests\TestCase;
use ZipArchive;

class ReturnAndShiftExportEndpointsTest extends TestCase
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

    public function test_topup_return_options_and_store_use_refactored_service(): void
    {
        $fixture = $this->createTenantFixture();
        $shift = Shift::query()->create([
            'tenant_id' => $fixture['tenant']->id,
            'opened_by_operator_id' => $fixture['operator']->id,
            'opened_at' => now()->subMinutes(2),
            'opening_cash' => 100000,
            'topups_cash_total' => 30000,
            'status' => 'open',
        ]);

        $fixture['client']->update([
            'balance' => 30000,
            'bonus' => 1000,
            'lifetime_topup' => 30000,
        ]);

        $topup = ClientTransaction::query()->create([
            'tenant_id' => $fixture['tenant']->id,
            'client_id' => $fixture['client']->id,
            'operator_id' => $fixture['operator']->id,
            'shift_id' => $shift->id,
            'type' => 'topup',
            'amount' => 30000,
            'bonus_amount' => 1000,
            'payment_method' => PaymentMethod::Cash->value,
            'comment' => 'Cash topup',
            'created_at' => now()->subMinute(),
            'updated_at' => now()->subMinute(),
        ]);

        $this->actingAsOwner($fixture['operator'])
            ->getJson('/api/clients/' . $fixture['client']->id . '/returns/options')
            ->assertOk()
            ->assertJsonPath('data.shift.id', $shift->id)
            ->assertJsonPath('data.topups.0.id', $topup->id)
            ->assertJsonPath('data.topups.0.payment_method', PaymentMethod::Cash->value);

        $this->actingAsOwner($fixture['operator'])
            ->postJson('/api/clients/' . $fixture['client']->id . '/returns', [
                'type' => 'topup',
                'source_id' => $topup->id,
            ])
            ->assertOk()
            ->assertJsonPath('data.return.source_id', $topup->id)
            ->assertJsonPath('data.return.type', 'topup');

        $this->assertDatabaseHas('returns', [
            'tenant_id' => $fixture['tenant']->id,
            'client_id' => $fixture['client']->id,
            'source_type' => 'client_transaction',
            'source_id' => $topup->id,
        ]);
        $this->assertDatabaseHas('client_transactions', [
            'tenant_id' => $fixture['tenant']->id,
            'client_id' => $fixture['client']->id,
            'type' => 'refund',
            'amount' => -30000,
            'bonus_amount' => -1000,
            'payment_method' => PaymentMethod::Cash->value,
        ]);
        $this->assertSame(0, (int) $fixture['client']->fresh()->balance);
        $this->assertSame(0, (int) $fixture['client']->fresh()->bonus);
        $this->assertSame(0, (int) $fixture['client']->fresh()->lifetime_topup);
        $this->assertSame(0, (int) $shift->fresh()->topups_cash_total);
    }

    public function test_package_return_refunds_balance_through_service_policy(): void
    {
        $fixture = $this->createTenantFixture();
        $shift = Shift::query()->create([
            'tenant_id' => $fixture['tenant']->id,
            'opened_by_operator_id' => $fixture['operator']->id,
            'opened_at' => now()->subMinutes(3),
            'opening_cash' => 50000,
            'status' => 'open',
        ]);

        $sale = PackageSale::query()->create([
            'tenant_id' => $fixture['tenant']->id,
            'client_id' => $fixture['client']->id,
            'package_id' => 12,
            'payment_method' => PaymentMethod::Balance->value,
            'shift_id' => null,
            'operator_id' => $fixture['operator']->id,
            'amount' => 45000,
            'created_at' => now()->subMinute(),
            'updated_at' => now()->subMinute(),
        ]);

        $this->actingAsOwner($fixture['operator'])
            ->postJson('/api/clients/' . $fixture['client']->id . '/returns', [
                'type' => 'package',
                'source_id' => $sale->id,
            ])
            ->assertOk()
            ->assertJsonPath('data.return.source_id', $sale->id)
            ->assertJsonPath('data.return.payment_method', PaymentMethod::Balance->value);

        $this->assertSame(45000, (int) $fixture['client']->fresh()->balance);
        $this->assertDatabaseHas('client_transactions', [
            'tenant_id' => $fixture['tenant']->id,
            'client_id' => $fixture['client']->id,
            'type' => 'refund',
            'amount' => 45000,
            'payment_method' => PaymentMethod::Balance->value,
            'comment' => 'Package return (balance)',
        ]);
    }

    public function test_shift_history_xml_export_returns_clean_workbook_response(): void
    {
        $fixture = $this->createTenantFixture();
        $this->createClosedShiftFixture($fixture);

        $response = $this->actingAsOwner($fixture['operator'])
            ->get('/api/shifts/history/export?from=2026-04-20&to=2026-04-22&lang=en');

        $response->assertOk();
        $this->assertStringContainsString('application/vnd.ms-excel', (string) $response->headers->get('content-type'));
        $this->assertStringContainsString('Shift History Export', $response->getContent());
        $this->assertStringContainsString('Shift History', $response->getContent());
    }

    public function test_shift_history_xlsx_export_returns_zip_workbook(): void
    {
        $fixture = $this->createTenantFixture();
        $this->createClosedShiftFixture($fixture);

        $response = $this->actingAsOwner($fixture['operator'])
            ->get('/api/shifts/history/export-xlsx?from=2026-04-20&to=2026-04-22&lang=en');

        $response->assertOk();
        $this->assertStringContainsString(
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            (string) $response->headers->get('content-type'),
        );
        $this->assertStringStartsWith('PK', $response->getContent());

        $tmp = tempnam(sys_get_temp_dir(), 'shift-history-test-');
        file_put_contents($tmp, $response->getContent());

        $zip = new ZipArchive();
        $this->assertTrue($zip->open($tmp));
        $this->assertNotFalse($zip->locateName('xl/workbook.xml'));
        $this->assertNotFalse($zip->locateName('xl/worksheets/sheet1.xml'));

        $workbookXml = $zip->getFromName('xl/workbook.xml');
        $this->assertIsString($workbookXml);
        $this->assertStringContainsString('Shift History', $workbookXml);

        $zip->close();
        @unlink($tmp);
    }

    private function createClosedShiftFixture(array $fixture): Shift
    {
        $shift = Shift::query()->create([
            'tenant_id' => $fixture['tenant']->id,
            'opened_by_operator_id' => $fixture['operator']->id,
            'closed_by_operator_id' => $fixture['operator']->id,
            'opened_at' => now()->subDays(1)->subHours(2),
            'closed_at' => now()->subDays(1),
            'opening_cash' => 100000,
            'closing_cash' => 145000,
            'topups_cash_total' => 50000,
            'topups_card_total' => 30000,
            'returns_total' => 5000,
            'diff_overage' => 0,
            'diff_shortage' => 5000,
            'status' => 'closed',
        ]);

        ClientTransaction::query()->create([
            'tenant_id' => $fixture['tenant']->id,
            'client_id' => $fixture['client']->id,
            'operator_id' => $fixture['operator']->id,
            'shift_id' => $shift->id,
            'type' => 'topup',
            'amount' => 50000,
            'bonus_amount' => 0,
            'payment_method' => PaymentMethod::Cash->value,
            'comment' => 'Shift topup',
            'created_at' => $shift->opened_at->copy()->addMinutes(10),
            'updated_at' => $shift->opened_at->copy()->addMinutes(10),
        ]);

        ShiftExpense::query()->create([
            'tenant_id' => $fixture['tenant']->id,
            'shift_id' => $shift->id,
            'operator_id' => $fixture['operator']->id,
            'title' => 'Snacks',
            'category' => 'kitchen',
            'amount' => 10000,
            'spent_at' => $shift->opened_at->copy()->addHour(),
            'created_at' => $shift->opened_at->copy()->addHour(),
            'updated_at' => $shift->opened_at->copy()->addHour(),
        ]);

        ReturnRecord::query()->create([
            'tenant_id' => $fixture['tenant']->id,
            'client_id' => $fixture['client']->id,
            'operator_id' => $fixture['operator']->id,
            'shift_id' => $shift->id,
            'type' => 'topup',
            'amount' => 5000,
            'payment_method' => PaymentMethod::Cash->value,
            'source_type' => 'client_transaction',
            'source_id' => 999,
            'created_at' => $shift->opened_at->copy()->addHour()->addMinutes(15),
            'updated_at' => $shift->opened_at->copy()->addHour()->addMinutes(15),
        ]);

        return $shift;
    }
}
