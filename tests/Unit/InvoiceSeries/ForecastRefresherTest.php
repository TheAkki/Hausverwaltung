<?php

// KI-generiert

namespace Tests\Unit\InvoiceSeries;

use App\Models\Cost;
use App\Models\Invoice;
use App\Models\ManagementGroup;
use App\Services\InvoiceSeries\CostAllocation;
use App\Services\InvoiceSeries\ForecastRefresher;
use App\Services\InvoiceSeries\InvoiceBookingRecorder;
use App\Services\InvoiceSeries\InvoiceForecastWriter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class ForecastRefresherTest extends TestCase
{
    use BuildsInvoiceFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-06-15');
        $this->buildFixtures();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /**
     * Monatliche Rechnung Jan–Jun 2026 mit einer Kategorie und Buchungen ($datum => $betrag).
     *
     * @param  array<string, float>  $bookings
     */
    private function loan(array $bookings, bool $variable = false, ?string $end = '2026-06-01'): Invoice
    {
        $invoice = $this->invoice(['disable_amount_cap' => $variable]);
        app(InvoiceForecastWriter::class)->writeAllocations($invoice, $this->managementGroup, '2026-01-01', $end, [
            new CostAllocation(100, $this->interest),
        ]);

        foreach ($bookings as $date => $amount) {
            app(InvoiceBookingRecorder::class)->record($invoice, $this->managementGroup, '2026-01-01', $date, [
                new CostAllocation($amount, $this->interest),
            ]);
        }

        return $invoice->fresh();
    }

    /**
     * @return list<float>
     */
    private function amounts(Invoice $invoice): array
    {
        return $invoice->costs()->orderBy('period_date')->pluck('amount')->map(fn ($amount) => (float) $amount)->all();
    }

    private function refresh(Invoice $invoice, ?int $exclude = null): void
    {
        app(ForecastRefresher::class)->refresh($invoice, $this->interest, $exclude);
    }

    private function mode(string $mode): void
    {
        $this->managementGroup->update(['variable_cost_forecast_mode' => $mode]);
    }

    // KI-generiert
    public function test_a_single_deviating_booking_does_not_change_the_forecast(): void
    {
        $invoice = $this->loan(['2026-02-01' => 103]);
        $this->refresh($invoice);

        $this->assertSame([100.0, 103.0, 100.0, 100.0, 100.0, 100.0], $this->amounts($invoice));
    }

    // KI-generiert
    public function test_varying_bookings_carry_the_last_amount_into_later_open_terms_only(): void
    {
        $invoice = $this->loan(['2026-02-01' => 101, '2026-04-01' => 103]);
        $this->refresh($invoice);

        $this->assertSame([100.0, 101.0, 100.0, 103.0, 103.0, 103.0], $this->amounts($invoice));
    }

    // KI-generiert
    public function test_regression_extrapolates_the_trend(): void
    {
        $this->mode(ManagementGroup::VARIABLE_FORECAST_REGRESSION);
        $invoice = $this->loan(['2026-01-01' => 100, '2026-02-01' => 100.5, '2026-03-01' => 101]);
        $this->refresh($invoice);

        $this->assertSame([100.0, 100.5, 101.0, 101.5, 102.0, 102.5], $this->amounts($invoice));
    }

    // KI-generiert
    public function test_mode_none_removes_open_terms_and_switching_back_refills_them(): void
    {
        $this->mode(ManagementGroup::VARIABLE_FORECAST_NONE);
        $invoice = $this->loan(['2026-01-01' => 90], variable: true);
        $this->refresh($invoice);
        $this->assertSame([90.0], $this->amounts($invoice));

        $this->mode(ManagementGroup::VARIABLE_FORECAST_LAST_BOOKING);
        $this->refresh($invoice->fresh());
        $this->assertSame([90.0, 90.0, 90.0, 90.0, 90.0, 90.0], $this->amounts($invoice));
    }

    // KI-generiert
    public function test_a_variable_invoice_without_bookings_and_an_excluded_row_stay_untouched(): void
    {
        $invoice = $this->loan([], variable: true);
        $this->refresh($invoice);
        $this->assertSame(array_fill(0, 6, 100.0), $this->amounts($invoice));

        app(InvoiceBookingRecorder::class)->record($invoice, $this->managementGroup, '2026-01-01', '2026-01-01', [new CostAllocation(80, $this->interest)]);
        $edited = $invoice->costs()->whereDate('period_date', '2026-03-01')->sole();
        $this->refresh($invoice, $edited->id);

        $this->assertSame([80.0, 80.0, 100.0, 80.0, 80.0, 80.0], $this->amounts($invoice));
    }

    // KI-generiert
    public function test_one_off_invoices_and_invoices_without_costable_are_skipped(): void
    {
        $once = $this->invoice(['periodicity' => Invoice::PERIODICITY_ONCE, 'disable_amount_cap' => true]);
        $orphan = $this->invoice(['costable_type' => null, 'costable_id' => null, 'disable_amount_cap' => true]);

        $this->refresh($once);
        $this->refresh($orphan);

        $this->assertSame(0, Cost::count());
    }

    // KI-generiert
    public function test_the_whole_management_group_is_refreshed_after_a_mode_change(): void
    {
        $unit = $this->managementGroup->managementUnits()->create(['street' => 'S', 'postal_code' => '1', 'city' => 'O']);
        $invoice = $this->invoice(['costable_type' => $unit->getMorphClass(), 'costable_id' => $unit->id, 'disable_amount_cap' => true]);
        app(InvoiceForecastWriter::class)->writeAllocations($invoice, $unit, '2026-01-01', '2026-03-01', [new CostAllocation(100, $this->interest)]);
        app(InvoiceBookingRecorder::class)->record($invoice, $unit, '2026-01-01', '2026-01-01', [new CostAllocation(70, $this->interest)]);

        app(ForecastRefresher::class)->refreshManagementGroup($this->managementGroup->fresh());

        $this->assertSame([70.0, 70.0, 70.0], $this->amounts($invoice));
    }
}
