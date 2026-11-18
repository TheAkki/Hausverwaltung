<?php

namespace Tests\Feature\Api;

use App\Models\Cost;
use App\Models\CostCategory;
use App\Models\Invoice;
use App\Models\ManagementGroup;
use App\Models\User;
use App\Services\InvoiceSeries\ForecastRefresher;
use Database\Seeders\CostCategorySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Api\Concerns\CreatesApiFixtures;
use Tests\TestCase;

// KI-generiert
class CostControllerTest extends TestCase
{
    use CreatesApiFixtures, RefreshDatabase;

    private const INVOICE_AMOUNT = 100;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CostCategorySeeder::class);
    }

    private function makeInvoice(User $user, ManagementGroup $managementGroup, array $attributes = []): Invoice
    {
        return Invoice::create([
            'invoice_issuer_id' => $this->makeIssuer($user)->id, 'amount' => self::INVOICE_AMOUNT,
            'invoice_date' => '2026-01-01', 'periodicity' => 'once',
            'costable_type' => $managementGroup->getMorphClass(), 'costable_id' => $managementGroup->id,
            ...$attributes,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(Invoice $invoice, ManagementGroup $managementGroup, array $overrides = []): array
    {
        return [
            'invoice_id' => $invoice->id,
            'costable_type' => 'management_group', 'costable_id' => $managementGroup->id,
            'billing_interval_id' => $managementGroup->billingIntervals()->first()->id,
            'cost_category_id' => CostCategory::first()->id,
            'amount' => 60,
            ...$overrides,
        ];
    }

    private function makeCost(Invoice $invoice, ManagementGroup $managementGroup, float $amount = 60): Cost
    {
        return $invoice->costs()->create([
            'costable_type' => $managementGroup->getMorphClass(), 'costable_id' => $managementGroup->id,
            'billing_interval_id' => $managementGroup->billingIntervals()->first()->id,
            'cost_category_id' => CostCategory::first()->id, 'amount' => $amount, 'period_date' => '2026-01-01',
        ]);
    }

    public function test_a_cost_row_can_be_created_on_the_invoice_date(): void
    {
        $user = $this->apiUser();
        $managementGroup = $this->makeManagementGroup($user);
        $invoice = $this->makeInvoice($user, $managementGroup);

        $response = $this->postJson(route('api.costs.store'), $this->payload($invoice, $managementGroup));

        $response->assertCreated();
        $this->assertSame('2026-01-01', Cost::first()->period_date->toDateString());
    }

    public function test_a_booked_cost_row_with_kontosystem_is_linked_to_its_account(): void
    {
        $user = $this->apiUser();
        $managementGroup = $this->makeManagementGroup($user, kontosystem: true);
        $account = $managementGroup->accounts()->create(['name' => 'Girokonto']);
        $invoice = $this->makeInvoice($user, $managementGroup);

        $this->postJson(route('api.costs.store'), $this->payload($invoice, $managementGroup, [
            'booking_date' => '2026-01-05', 'account_id' => $account->id, 'period_date' => '2026-01-01',
        ]))->assertCreated();

        $this->assertSame([$account->id], Cost::first()->accounts->pluck('id')->all());
    }

    public function test_booking_with_kontosystem_requires_an_account(): void
    {
        $user = $this->apiUser();
        $managementGroup = $this->makeManagementGroup($user, kontosystem: true);
        $invoice = $this->makeInvoice($user, $managementGroup);

        $this->postJson(route('api.costs.store'), $this->payload($invoice, $managementGroup, ['booking_date' => '2026-01-05']))
            ->assertUnprocessable()->assertJsonValidationErrors('account_id');
    }

    public function test_the_cost_rows_may_not_exceed_the_invoice_amount(): void
    {
        $user = $this->apiUser();
        $managementGroup = $this->makeManagementGroup($user);
        $invoice = $this->makeInvoice($user, $managementGroup);
        $this->makeCost($invoice, $managementGroup);

        $this->postJson(route('api.costs.store'), $this->payload($invoice, $managementGroup))
            ->assertUnprocessable()->assertJsonValidationErrors('amount');
    }

    public function test_the_amount_cap_can_be_disabled(): void
    {
        $user = $this->apiUser();
        $managementGroup = $this->makeManagementGroup($user);
        $invoice = $this->makeInvoice($user, $managementGroup, ['disable_amount_cap' => true]);

        $this->postJson(route('api.costs.store'), $this->payload($invoice, $managementGroup, ['amount' => 500]))->assertCreated();
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function costableTypes(): array
    {
        return ['Verwaltungseinheit' => ['management_unit'], 'Mieteinheit' => ['rental_unit']];
    }

    #[DataProvider('costableTypes')]
    public function test_a_cost_row_can_be_assigned_to_lower_levels(string $type): void
    {
        $user = $this->apiUser();
        $rentalUnit = $this->makeRentalUnit($user);
        $managementGroup = $rentalUnit->managementUnit->managementGroup;
        $costable = $type === 'rental_unit' ? $rentalUnit : $rentalUnit->managementUnit;
        $invoice = $this->makeInvoice($user, $managementGroup);

        $this->postJson(route('api.costs.store'), $this->payload($invoice, $managementGroup, [
            'costable_type' => $type, 'costable_id' => $costable->id,
        ]))->assertCreated();
    }

    public function test_a_lease_cost_row_may_not_book_on_an_account(): void
    {
        $user = $this->apiUser();
        $lease = $this->makeLease($user);
        $managementGroup = $lease->rentalUnit->managementUnit->managementGroup;
        $account = $managementGroup->accounts()->create(['name' => 'Girokonto']);
        $invoice = $this->makeInvoice($user, $managementGroup);

        $this->postJson(route('api.costs.store'), $this->payload($invoice, $managementGroup, [
            'costable_type' => 'lease', 'costable_id' => $lease->id, 'account_id' => $account->id,
        ]))->assertUnprocessable()->assertJsonValidationErrors('account_id');
    }

    public function test_a_missing_costable_fails_validation(): void
    {
        $user = $this->apiUser();
        $managementGroup = $this->makeManagementGroup($user);
        $invoice = $this->makeInvoice($user, $managementGroup);

        $this->postJson(route('api.costs.store'), $this->payload($invoice, $managementGroup, [
            'costable_type' => null, 'costable_id' => null,
        ]))->assertUnprocessable()->assertJsonValidationErrors(['costable_type', 'costable_id']);
    }

    public function test_a_cost_row_cannot_be_added_to_a_foreign_invoice(): void
    {
        $owner = User::factory()->create();
        $managementGroup = $this->makeManagementGroup($owner);
        $invoice = $this->makeInvoice($owner, $managementGroup);
        $this->apiUser();

        $this->postJson(route('api.costs.store'), $this->payload($invoice, $managementGroup))->assertForbidden();
    }

    public function test_saving_a_cost_row_of_a_recurring_invoice_refreshes_its_forecast(): void
    {
        $user = $this->apiUser();
        $managementGroup = $this->makeManagementGroup($user);
        $invoice = $this->makeInvoice($user, $managementGroup, [
            'periodicity' => 'monthly', 'recurrence_start' => '2026-01-01', 'recurrence_end' => '2026-12-31',
        ]);
        $this->mock(ForecastRefresher::class, fn (MockInterface $mock) => $mock->shouldReceive('refresh')->once());

        $this->postJson(route('api.costs.store'), $this->payload($invoice, $managementGroup))->assertCreated();
    }

    public function test_a_cost_row_can_be_updated_and_booked_on_an_account(): void
    {
        $user = $this->apiUser();
        $managementGroup = $this->makeManagementGroup($user, kontosystem: true);
        $account = $managementGroup->accounts()->create(['name' => 'Girokonto']);
        $invoice = $this->makeInvoice($user, $managementGroup);
        $cost = $this->makeCost($invoice, $managementGroup);

        $this->putJson(route('api.costs.update', $cost), [
            'billing_interval_id' => $cost->billing_interval_id, 'cost_category_id' => $cost->cost_category_id,
            'amount' => 90, 'booking_date' => '2026-01-05', 'account_id' => $account->id, 'confirm_booking' => 1,
        ])->assertOk();

        $cost->refresh();
        $this->assertEquals(90, $cost->amount);
        $this->assertSame([$account->id], $cost->accounts->pluck('id')->all());
    }

    public function test_updating_without_an_account_detaches_it(): void
    {
        $user = $this->apiUser();
        $managementGroup = $this->makeManagementGroup($user, kontosystem: true);
        $account = $managementGroup->accounts()->create(['name' => 'Girokonto']);
        $invoice = $this->makeInvoice($user, $managementGroup);
        $cost = $this->makeCost($invoice, $managementGroup);
        $cost->accounts()->attach($account->id, ['amount' => $cost->amount]);

        $this->putJson(route('api.costs.update', $cost), [
            'billing_interval_id' => $cost->billing_interval_id, 'cost_category_id' => $cost->cost_category_id,
            'amount' => 60, 'period_date' => '2026-01-01',
        ])->assertOk();

        $this->assertCount(0, $cost->fresh()->accounts);
    }

    public function test_an_update_may_not_exceed_the_invoice_amount(): void
    {
        $user = $this->apiUser();
        $managementGroup = $this->makeManagementGroup($user);
        $invoice = $this->makeInvoice($user, $managementGroup);
        $this->makeCost($invoice, $managementGroup, 50);
        $cost = $this->makeCost($invoice, $managementGroup, 50);

        $this->putJson(route('api.costs.update', $cost), [
            'billing_interval_id' => $cost->billing_interval_id, 'cost_category_id' => $cost->cost_category_id, 'amount' => 80,
        ])->assertUnprocessable()->assertJsonValidationErrors('amount');
    }

    public function test_a_foreign_cost_row_cannot_be_updated(): void
    {
        $owner = User::factory()->create();
        $managementGroup = $this->makeManagementGroup($owner);
        $cost = $this->makeCost($this->makeInvoice($owner, $managementGroup), $managementGroup);
        $this->apiUser();

        $this->putJson(route('api.costs.update', $cost), [
            'billing_interval_id' => $cost->billing_interval_id, 'cost_category_id' => $cost->cost_category_id, 'amount' => 1,
        ])->assertForbidden();
    }

    public function test_a_cost_row_can_be_deleted(): void
    {
        $user = $this->apiUser();
        $managementGroup = $this->makeManagementGroup($user);
        $cost = $this->makeCost($this->makeInvoice($user, $managementGroup), $managementGroup);

        $this->deleteJson(route('api.costs.destroy', $cost))->assertNoContent();
        $this->assertNull(Cost::find($cost->id));
    }

    public function test_a_foreign_cost_row_cannot_be_deleted(): void
    {
        $owner = User::factory()->create();
        $managementGroup = $this->makeManagementGroup($owner);
        $cost = $this->makeCost($this->makeInvoice($owner, $managementGroup), $managementGroup);
        $this->apiUser();

        $this->deleteJson(route('api.costs.destroy', $cost))->assertForbidden();
        $this->assertNotNull(Cost::find($cost->id));
    }

    public function test_a_refund_takes_negative_cost_rows_up_to_its_amount(): void
    {
        $user = $this->apiUser();
        $managementGroup = $this->makeManagementGroup($user);
        $refund = $this->makeInvoice($user, $managementGroup, ['amount' => -self::INVOICE_AMOUNT]);

        $this->postJson(route('api.costs.store'), $this->payload($refund, $managementGroup, ['amount' => -60]))->assertCreated();
        $this->postJson(route('api.costs.store'), $this->payload($refund, $managementGroup, ['amount' => -50]))
            ->assertUnprocessable()->assertJsonValidationErrors('amount');
    }

    public function test_a_refund_rejects_a_positive_cost_row(): void
    {
        $user = $this->apiUser();
        $managementGroup = $this->makeManagementGroup($user);
        $refund = $this->makeInvoice($user, $managementGroup, ['amount' => -self::INVOICE_AMOUNT]);

        $this->postJson(route('api.costs.store'), $this->payload($refund, $managementGroup, ['amount' => 10]))
            ->assertUnprocessable()->assertJsonValidationErrors('amount');
    }

    // KI-generiert
    public function test_the_forecast_refresh_can_be_switched_off_for_imports(): void
    {
        $user = $this->apiUser();
        $managementGroup = $this->makeManagementGroup($user);
        $invoice = $this->makeInvoice($user, $managementGroup, [
            'periodicity' => 'monthly', 'recurrence_start' => '2026-01-01', 'recurrence_end' => '2026-12-31',
        ]);
        $this->mock(ForecastRefresher::class, fn (MockInterface $mock) => $mock->shouldNotReceive('refresh'));

        $id = $this->postJson(route('api.costs.store'), $this->payload($invoice, $managementGroup, [
            'refresh_forecast' => false, 'note' => 'Importiert',
        ]))->assertCreated()->assertJsonPath('note', 'Importiert')->json('id');

        $this->putJson(route('api.costs.update', $id), [
            'billing_interval_id' => $managementGroup->billingIntervals()->first()->id,
            'cost_category_id' => CostCategory::first()->id, 'amount' => 50, 'note' => 'Geändert', 'refresh_forecast' => false,
        ])->assertOk()->assertJsonPath('note', 'Geändert');
    }
}
