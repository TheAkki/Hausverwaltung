<?php

namespace Tests\Unit;

use App\Models\Cost;
use App\Models\CostCategory;
use App\Models\Invoice;
use App\Models\InvoiceIssuer;
use App\Models\ManagementGroup;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Covers Invoice's small presentation helpers (periodicityLabel, managementGroup,
 * costableLabel, isFullyBooked) across all periodicities and costable types.
 */
class InvoiceModelTest extends TestCase
{
    use RefreshDatabase;

    private function makeInvoice(string $periodicity): Invoice
    {
        $issuer = InvoiceIssuer::create(['name' => 'Stadtwerke', 'street' => 'A', 'postal_code' => '1', 'city' => 'B']);

        return Invoice::create([
            'invoice_issuer_id' => $issuer->id, 'amount' => 100,
            'invoice_date' => '2026-01-01', 'periodicity' => $periodicity,
        ]);
    }

    private function addCostTo(Invoice $invoice, ManagementGroup $managementGroup, ?string $bookingDate): Cost
    {
        return Cost::create([
            'invoice_id' => $invoice->id,
            'costable_type' => $managementGroup->getMorphClass(),
            'costable_id' => $managementGroup->id,
            'billing_interval_id' => $managementGroup->billingIntervals()->first()->id,
            'cost_category_id' => CostCategory::firstOrCreate(['name' => 'Test'])->id,
            'amount' => 10,
            'period_date' => '2026-01-01',
            'booking_date' => $bookingDate,
        ]);
    }

    public function test_periodicity_labels_are_translated_to_german(): void
    {
        $this->assertSame('Wöchentlich', $this->makeInvoice('weekly')->periodicityLabel());
        $this->assertSame('2-wöchentlich', $this->makeInvoice('biweekly')->periodicityLabel());
        $this->assertSame('Monatlich', $this->makeInvoice('monthly')->periodicityLabel());
        $this->assertSame('Quartalsweise', $this->makeInvoice('quarterly')->periodicityLabel());
        $this->assertSame('Jährlich', $this->makeInvoice('yearly')->periodicityLabel());
        $this->assertSame('Händisch', $this->makeInvoice('manual')->periodicityLabel());
        $this->assertSame('Einmalig', $this->makeInvoice('once')->periodicityLabel());
    }

    public function test_management_group_and_costable_label_resolve_through_a_management_unit(): void
    {
        $user = User::factory()->create();
        $group = $user->groups()->first();
        $managementGroup = ManagementGroup::create(['description' => 'MG', 'user_group_id' => $group->id]);
        $managementUnit = $managementGroup->managementUnits()->create(['street' => 'Teststr 1', 'postal_code' => '1', 'city' => 'B']);

        $invoice = $this->makeInvoice('once');
        $invoice->update(['costable_type' => $managementUnit->getMorphClass(), 'costable_id' => $managementUnit->id]);

        $this->assertTrue($invoice->managementGroup()->is($managementGroup));
        $this->assertSame('Verwaltungseinheit: Teststr 1', $invoice->costableLabel());
    }

    public function test_an_invoice_with_no_cost_rows_at_all_is_not_fully_booked(): void
    {
        $invoice = $this->makeInvoice('once');

        $this->assertFalse($invoice->isFullyBooked());
    }

    public function test_an_invoice_is_fully_booked_once_every_cost_row_has_a_booking_date(): void
    {
        $user = User::factory()->create();
        $group = $user->groups()->first();
        $managementGroup = ManagementGroup::create(['description' => 'MG', 'user_group_id' => $group->id]);
        $invoice = $this->makeInvoice('monthly');

        $this->addCostTo($invoice, $managementGroup, '2026-01-05');
        $this->addCostTo($invoice, $managementGroup, '2026-02-05');
        $invoice->refresh();

        $this->assertTrue($invoice->isFullyBooked());
    }

    public function test_an_invoice_with_at_least_one_open_cost_row_is_not_fully_booked(): void
    {
        $user = User::factory()->create();
        $group = $user->groups()->first();
        $managementGroup = ManagementGroup::create(['description' => 'MG', 'user_group_id' => $group->id]);
        $invoice = $this->makeInvoice('monthly');

        $this->addCostTo($invoice, $managementGroup, '2026-01-05');
        $this->addCostTo($invoice, $managementGroup, null);
        $invoice->refresh();

        $this->assertFalse($invoice->isFullyBooked());
    }

    public function test_a_once_off_invoice_uses_its_own_booking_date_as_range_start_and_has_no_range_end(): void
    {
        $invoice = $this->makeInvoice('once');
        $invoice->update(['booking_date' => '2026-03-10']);

        $this->assertSame('2026-03-10', $invoice->bookingRangeStart()->toDateString());
        $this->assertNull($invoice->bookingRangeEnd());
    }

    public function test_a_once_off_invoice_without_a_booking_date_has_no_range_start_either(): void
    {
        $invoice = $this->makeInvoice('once');

        $this->assertNull($invoice->bookingRangeStart());
        $this->assertNull($invoice->bookingRangeEnd());
    }

    public function test_a_recurring_invoices_range_spans_the_earliest_to_latest_booked_cost_ignoring_open_ones(): void
    {
        $user = User::factory()->create();
        $group = $user->groups()->first();
        $managementGroup = ManagementGroup::create(['description' => 'MG', 'user_group_id' => $group->id]);
        $invoice = $this->makeInvoice('monthly');

        $this->addCostTo($invoice, $managementGroup, '2026-02-05');
        $this->addCostTo($invoice, $managementGroup, '2026-01-05');
        $this->addCostTo($invoice, $managementGroup, null);
        $invoice->refresh();

        $this->assertSame('2026-01-05', $invoice->bookingRangeStart()->toDateString());
        $this->assertSame('2026-02-05', $invoice->bookingRangeEnd()->toDateString());
    }

    public function test_a_recurring_invoice_with_no_bookings_yet_has_no_range_at_all(): void
    {
        $user = User::factory()->create();
        $group = $user->groups()->first();
        $managementGroup = ManagementGroup::create(['description' => 'MG', 'user_group_id' => $group->id]);
        $invoice = $this->makeInvoice('monthly');

        $this->addCostTo($invoice, $managementGroup, null);
        $invoice->refresh();

        $this->assertNull($invoice->bookingRangeStart());
        $this->assertNull($invoice->bookingRangeEnd());
    }
}
