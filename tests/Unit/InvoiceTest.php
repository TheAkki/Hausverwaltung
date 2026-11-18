<?php

namespace Tests\Unit;

use App\Models\CostCategory;
use App\Models\Invoice;
use App\Models\InvoiceIssuer;
use App\Models\ManagementGroup;
use App\Models\ManagementUnit;
use App\Models\User;
use App\Rules\CostSumWithinInvoiceAmount;
use App\Services\InvoiceSeries\InvoiceSeriesService;
use Database\Seeders\CostCategorySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

class InvoiceTest extends TestCase
{
    use RefreshDatabase;

    private ManagementUnit $managementUnit;

    private ManagementGroup $managementGroup;

    private CostCategory $category;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(CostCategorySeeder::class);

        $user = User::factory()->create();
        $group = $user->groups()->first();

        $this->managementGroup = ManagementGroup::create([
            'description' => 'Test Group',
            'user_group_id' => $group->id,
        ]);

        $this->managementUnit = $this->managementGroup->managementUnits()->create([
            'street' => 'Teststraße 1',
            'postal_code' => '12345',
            'city' => 'Teststadt',
        ]);

        $this->category = CostCategory::first();
    }

    private function makeIssuer(): InvoiceIssuer
    {
        return InvoiceIssuer::create([
            'name' => 'Stadtwerke',
            'street' => 'A',
            'postal_code' => '1',
            'city' => 'B',
        ]);
    }

    public function test_effective_amount_falls_back_to_amount(): void
    {
        $invoice = Invoice::create([
            'invoice_issuer_id' => $this->makeIssuer()->id,
            'amount' => 100,
            'invoice_date' => '2026-01-01',
            'periodicity' => 'once',
        ]);

        $this->assertSame(100.0, $invoice->effectiveAmount());

        $invoice->effective_amount = 80;
        $this->assertSame(80.0, $invoice->effectiveAmount());
    }

    public function test_cost_sum_rule_rejects_amounts_exceeding_the_cap(): void
    {
        $rule = new CostSumWithinInvoiceAmount(80.0, 100.0);

        $failing = Validator::make(['amount' => 30], ['amount' => [$rule]]);
        $this->assertTrue($failing->fails());

        $passing = Validator::make(['amount' => 20], ['amount' => [$rule]]);
        $this->assertFalse($passing->fails());
    }

    // KI-generiert
    public function test_cost_sum_rule_rejects_a_negative_cost_on_a_positive_invoice(): void
    {
        $rule = new CostSumWithinInvoiceAmount(0.0, 100.0);

        $this->assertTrue(Validator::make(['amount' => -10], ['amount' => [$rule]])->fails());
    }

    // KI-generiert
    public function test_cost_sum_rule_bounds_a_negative_invoice_between_its_amount_and_zero(): void
    {
        $rule = new CostSumWithinInvoiceAmount(-60.0, -100.0);

        $this->assertFalse(Validator::make(['amount' => -40], ['amount' => [$rule]])->fails());
        $this->assertTrue(Validator::make(['amount' => -41], ['amount' => [$rule]])->fails());
        $this->assertTrue(Validator::make(['amount' => 70], ['amount' => [$rule]])->fails());
    }

    public function test_once_invoice_generates_a_single_cost_in_the_current_billing_interval(): void
    {
        $invoice = Invoice::create([
            'invoice_issuer_id' => $this->makeIssuer()->id,
            'amount' => 150,
            'invoice_date' => '2026-06-15',
            'periodicity' => 'once',
        ]);

        $costs = app(InvoiceSeriesService::class)->write($invoice, $this->managementUnit, $this->category);

        $this->assertCount(1, $costs);
        $this->assertEquals(150.0, $costs->first()->amount);
        $this->assertSame(1, $this->managementGroup->billingIntervals()->count());
    }

    public function test_monthly_invoice_generates_one_cost_per_month(): void
    {
        $invoice = Invoice::create([
            'invoice_issuer_id' => $this->makeIssuer()->id,
            'amount' => 25,
            'invoice_date' => '2026-01-01',
            'periodicity' => 'monthly',
            'recurrence_start' => '2026-01-01',
            'recurrence_end' => '2026-06-01',
        ]);

        $costs = app(InvoiceSeriesService::class)->write($invoice, $this->managementUnit, $this->category);

        $this->assertCount(6, $costs);
        $this->assertEquals(150.0, $costs->sum('amount'));
    }

    public function test_a_monthly_invoice_starting_on_the_31st_never_skips_a_short_month(): void
    {
        // Naively chaining Carbon's addMonth() overflows past short months:
        // Jan 31 -> addMonth() -> Mar 3, skipping February entirely and permanently
        // shifting every later occurrence's day from 31 to 3. Each occurrence must
        // instead be computed from the original start date, so the invoice recurs
        // in every month (clamped to Feb 28) and returns to day 31 as soon as the
        // month is long enough again.
        $invoice = Invoice::create([
            'invoice_issuer_id' => $this->makeIssuer()->id,
            'amount' => 10,
            'invoice_date' => '2026-01-31',
            'periodicity' => 'monthly',
            'recurrence_start' => '2026-01-31',
            'recurrence_end' => '2026-04-30',
        ]);

        $costs = app(InvoiceSeriesService::class)->write($invoice, $this->managementUnit, $this->category);

        $this->assertSame(
            ['2026-01-31', '2026-02-28', '2026-03-31', '2026-04-30'],
            $costs->map(fn ($cost) => $cost->period_date->toDateString())->all()
        );
    }

    public function test_once_invoice_cost_inherits_the_invoices_booking_date(): void
    {
        $invoice = Invoice::create([
            'invoice_issuer_id' => $this->makeIssuer()->id,
            'amount' => 150,
            'invoice_date' => '2026-06-15',
            'booking_date' => '2026-06-20',
            'periodicity' => 'once',
        ]);

        $cost = app(InvoiceSeriesService::class)->write($invoice, $this->managementUnit, $this->category)->first();

        $this->assertSame('2026-06-20', $cost->booking_date->toDateString());
        $this->assertFalse($cost->isOpen());
    }

    public function test_monthly_invoice_costs_start_unbooked_so_each_occurrence_can_be_confirmed_individually(): void
    {
        // A loan repayment (Zins/Tilgung) is monthly, but each month's payment must be
        // booked separately once it actually happens, and its Zins/Tilgung split can
        // differ from month to month (see the Kostenzeile edit form on the invoice page).
        $invoice = Invoice::create([
            'invoice_issuer_id' => $this->makeIssuer()->id,
            'amount' => 1000,
            'invoice_date' => '2026-01-01',
            'booking_date' => '2026-01-01',
            'periodicity' => 'monthly',
            'recurrence_start' => '2026-01-01',
            'recurrence_end' => '2026-03-01',
        ]);

        $costs = app(InvoiceSeriesService::class)->write($invoice, $this->managementUnit, $this->category);

        $this->assertCount(3, $costs);
        $this->assertTrue($costs->every(fn ($cost) => $cost->isOpen()));
    }

    /**
     * Konzept.md "### Rechnung": "Wenn ein Enddatum gesetzt ist, ... nehme das
     * [ursprüngliche] Abrechnungsintervall [für alle Buchungen]." A bounded (Ende
     * gesetzt) Rechnung's tail end – here a single occurrence poking one month past
     * the year boundary – stays in the ONE interval active when the recurrence
     * started, instead of fragmenting into a near-empty extra year's interval.
     */
    public function test_a_bounded_recurring_invoice_spanning_into_a_new_year_stays_in_its_original_billing_interval(): void
    {
        $this->assertSame(1, $this->managementGroup->billingIntervals()->count());

        $invoice = Invoice::create([
            'invoice_issuer_id' => $this->makeIssuer()->id,
            'amount' => 10,
            'invoice_date' => '2026-11-01',
            'periodicity' => 'monthly',
            'recurrence_start' => '2026-11-01',
            'recurrence_end' => '2027-02-01',
        ]);

        $costs = app(InvoiceSeriesService::class)->write($invoice, $this->managementUnit, $this->category);

        $this->assertCount(4, $costs);

        // Still just the one interval the ManagementGroup already had – none of the
        // four occurrences (including the two actually dated in 2027) triggered a new
        // one, since this Rechnung has an explicit "Ende".
        $this->assertSame(1, $this->managementGroup->billingIntervals()->count());
        $originalInterval = $this->managementGroup->billingIntervals()->first();
        $this->assertTrue($costs->every(fn ($cost) => $cost->billing_interval_id === $originalInterval->id));
    }

    /**
     * Counterpart to the bounded case above: without an "Ende" at all (the domain's
     * open-ended costs, e.g. an ongoing loan's Zins/Tilgung – Konzept.md
     * "### Rechnung": "Wenn kein Enddatum vorhanden ist, dann nehme für das
     * Abrechnungsintervall das Datum der geplanten Buchung"), each occurrence still
     * maps to whichever interval actually covers its own date, auto-creating a new
     * one as the recurrence genuinely reaches into a later year.
     */
    public function test_an_open_ended_recurring_invoice_spanning_into_a_new_year_creates_a_new_billing_interval(): void
    {
        Carbon::setTestNow('2027-01-15');

        $this->assertSame(1, $this->managementGroup->billingIntervals()->count());

        $invoice = Invoice::create([
            'invoice_issuer_id' => $this->makeIssuer()->id,
            'amount' => 10,
            'invoice_date' => '2026-11-01',
            'periodicity' => 'monthly',
            'recurrence_start' => '2026-11-01',
            // No recurrence_end – open-ended, so the forecast runs through the end of
            // the following year (OccurrenceSchedule::OPEN_ENDED_HORIZON_YEARS; today is
            // frozen above at 15.1.2027).
        ]);

        $costs = app(InvoiceSeriesService::class)->write($invoice, $this->managementUnit, $this->category);

        Carbon::setTestNow();

        $this->assertCount(26, $costs); // Nov 2026 … Dec 2028
        $this->assertSame(3, $this->managementGroup->billingIntervals()->count());

        $years = $this->managementGroup->billingIntervals()->pluck('start_date')
            ->map(fn ($date) => $date->year)
            ->sort()
            ->values();
        $this->assertSame([2026, 2027, 2028], $years->all());
    }

    public function test_manual_invoice_uses_occurrence_dates_and_amounts(): void
    {
        $invoice = Invoice::create([
            'invoice_issuer_id' => $this->makeIssuer()->id,
            'amount' => 70,
            'invoice_date' => '2026-01-01',
            'periodicity' => 'manual',
        ]);
        $invoice->occurrences()->create(['occurrence_date' => '2026-02-01', 'amount' => 20]);
        $invoice->occurrences()->create(['occurrence_date' => '2026-05-01', 'amount' => 50]);
        $invoice->load('occurrences');

        $costs = app(InvoiceSeriesService::class)->write($invoice, $this->managementUnit, $this->category);

        $this->assertCount(2, $costs);
        $this->assertEquals(70.0, $costs->sum('amount'));
    }
}
