<?php

// KI-generiert

namespace Tests\Unit\InvoiceSeries;

use App\Models\Cost;
use App\Models\Invoice;
use App\Services\InvoiceSeries\CostAllocation;
use App\Services\InvoiceSeries\CostSegment;
use App\Services\InvoiceSeries\InvalidSeriesException;
use App\Services\InvoiceSeries\InvoiceForecastWriter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class InvoiceForecastWriterTest extends TestCase
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

    private function writer(): InvoiceForecastWriter
    {
        return app(InvoiceForecastWriter::class);
    }

    // KI-generiert
    public function test_a_bounded_series_writes_one_row_per_term_and_category_in_its_start_interval(): void
    {
        $invoice = $this->invoice();
        $this->writer()->writeAllocations($invoice, $this->managementGroup, '2026-11-01', '2027-02-01', [
            new CostAllocation(60, $this->interest), new CostAllocation(40, $this->repayment),
        ]);

        $costs = Cost::orderBy('period_date')->get();
        $this->assertCount(8, $costs);
        $this->assertSame(1, $costs->pluck('billing_interval_id')->unique()->count());
        $this->assertSame('2026-12-31', $costs->first()->billingInterval->end_date->toDateString());
        $this->assertSame(['2026-11-01', '2027-02-01'], [$invoice->fresh()->recurrence_start->toDateString(), $invoice->fresh()->recurrence_end->toDateString()]);
    }

    // KI-generiert
    public function test_an_open_series_uses_the_interval_of_each_term_up_to_the_horizon(): void
    {
        $this->writer()->writeAllocations($this->invoice(), $this->managementGroup, '2026-11-01', null, [new CostAllocation(100, $this->interest)]);

        $this->assertSame('2027-12-01', Carbon::parse(Cost::max('period_date'))->toDateString());
        $this->assertSame(2, Cost::distinct()->count('billing_interval_id'));
    }

    // KI-generiert
    public function test_a_pinned_interval_applies_to_every_term(): void
    {
        $pin = $this->interval('2020-01-01', '2020-12-31');
        $this->writer()->writeAllocations($this->invoice(), $this->managementGroup, '2026-11-01', null, [new CostAllocation(100, $this->interest)], $pin);

        $this->assertSame([$pin->id], Cost::distinct()->pluck('billing_interval_id')->all());
    }

    // KI-generiert
    public function test_a_one_off_row_inherits_the_booking_date_and_keeps_no_recurrence(): void
    {
        $invoice = $this->invoice(['periodicity' => Invoice::PERIODICITY_ONCE, 'booking_date' => '2026-02-03']);
        $this->writer()->writeAllocations($invoice, $this->managementGroup, '2026-02-03', null, [new CostAllocation(100, $this->interest)]);

        $this->assertSame('2026-02-03', Cost::sole()->booking_date->toDateString());
        $this->assertNull($invoice->fresh()->recurrence_start);
    }

    // KI-generiert
    public function test_writing_again_does_not_duplicate_rows_of_the_same_month(): void
    {
        $invoice = $this->invoice();
        $allocations = [new CostAllocation(100, $this->interest)];
        $this->writer()->writeAllocations($invoice, $this->managementGroup, '2026-01-01', '2026-03-01', $allocations);
        Cost::orderBy('period_date')->first()->update(['period_date' => '2026-01-28']);

        $this->writer()->writeAllocations($invoice, $this->managementGroup, '2026-01-01', '2026-03-01', $allocations);

        $this->assertSame(3, Cost::count());
    }

    // KI-generiert
    public function test_weekly_series_compare_exact_dates(): void
    {
        $invoice = $this->invoice(['periodicity' => Invoice::PERIODICITY_WEEKLY]);
        $allocations = [new CostAllocation(10, $this->interest)];
        $this->writer()->writeAllocations($invoice, $this->managementGroup, '2026-01-01', '2026-01-29', $allocations);
        $this->writer()->writeAllocations($invoice, $this->managementGroup, '2026-01-01', '2026-01-29', $allocations);

        $this->assertSame(5, Cost::count());
    }

    // KI-generiert
    public function test_segments_take_amount_and_note_from_the_covering_segment(): void
    {
        $invoice = $this->invoice();
        $this->writer()->writeSegments($invoice, $this->managementGroup, [
            new CostSegment('2026-01-01', '2026-02-28', 100, 'alt', [new CostAllocation(100, $this->interest)]),
            new CostSegment('2026-03-01', '2026-04-30', 120, 'neu', [new CostAllocation(120, $this->interest)]),
        ]);

        $this->assertSame([100.0, 100.0, 120.0, 120.0], Cost::orderBy('period_date')->pluck('amount')->map(fn ($a) => (float) $a)->all());
        $this->assertSame(['alt', 'alt', 'neu', 'neu'], Cost::orderBy('period_date')->pluck('note')->all());
        $this->assertSame('2026-04-30', $invoice->fresh()->recurrence_end->toDateString());
    }

    // KI-generiert
    public function test_open_segments_resolve_each_terms_interval_and_gaps_are_rejected(): void
    {
        $this->writer()->writeSegments($this->invoice(), $this->managementGroup, [
            new CostSegment('2026-12-01', null, 100, null, [new CostAllocation(100, $this->interest)]),
        ]);
        $this->assertSame(2, Cost::distinct()->count('billing_interval_id'));

        $this->expectException(\InvalidArgumentException::class);
        $this->writer()->writeSegments($this->invoice(['amount' => 5]), $this->managementGroup, [
            new CostSegment('2026-01-01', '2026-01-31', 5, null, [new CostAllocation(5, $this->interest)]),
            new CostSegment('2026-03-01', '2026-03-31', 5, null, [new CostAllocation(5, $this->interest)]),
        ]);
    }

    // KI-generiert
    public function test_an_end_before_the_start_is_rejected(): void
    {
        $this->expectException(InvalidSeriesException::class);
        $this->expectExceptionMessage('Das Ende der Wiederholung (1026-01-31) liegt vor ihrem Beginn (2025-03-01).');

        $this->writer()->writeAllocations($this->invoice(), $this->managementGroup, '2025-03-01', '1026-01-31', [new CostAllocation(1, $this->interest)]);
    }

    // KI-generiert
    public function test_completing_without_remembering_keeps_the_recurrence(): void
    {
        $invoice = $this->invoice(['recurrence_start' => '2026-01-01', 'recurrence_end' => '2026-12-01']);
        $this->writer()->writeAllocations($invoice, $this->managementGroup, '2026-01-01', '2026-02-01', [new CostAllocation(1, $this->interest)], remember: false);

        $this->assertSame('2026-12-01', $invoice->fresh()->recurrence_end->toDateString());
        $this->assertSame(2, Cost::count());
    }

    // KI-generiert
    public function test_manual_invoices_get_one_row_per_entered_term(): void
    {
        $invoice = $this->invoice(['periodicity' => Invoice::PERIODICITY_MANUAL]);
        $invoice->occurrences()->create(['occurrence_date' => '2026-02-10', 'amount' => 40]);
        $invoice->occurrences()->create(['occurrence_date' => '2027-03-10', 'amount' => 60]);

        $this->writer()->writeManual($invoice->load('occurrences'), $this->managementGroup, $this->interest);

        $this->assertSame([40.0, 60.0], Cost::orderBy('period_date')->pluck('amount')->map(fn ($a) => (float) $a)->all());
        $this->assertSame(2, Cost::distinct()->count('billing_interval_id'));
    }

    // KI-generiert
    public function test_missing_terms_are_filled_with_the_given_amounts(): void
    {
        $invoice = $this->invoice();
        $this->writer()->writeAllocations($invoice, $this->managementGroup, '2026-01-01', '2026-03-01', [new CostAllocation(1, $this->interest)]);
        Cost::whereDate('period_date', '2026-02-01')->delete();

        $this->writer()->fillMissing($invoice, $this->managementGroup, $this->interest, '2026-01-01', '2026-03-01', fn (int $n) => 10.0 * $n);

        $this->assertSame([1.0, 10.0, 1.0], Cost::orderBy('period_date')->pluck('amount')->map(fn ($a) => (float) $a)->all());
    }
}
