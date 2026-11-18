<?php

// KI-generiert

namespace Tests\Unit\InvoiceSeries;

use App\Models\Cost;
use App\Models\Invoice;
use App\Services\InvoiceSeries\CostAllocation;
use App\Services\InvoiceSeries\InvoiceBookingRecorder;
use App\Services\InvoiceSeries\InvoiceForecastWriter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class InvoiceBookingRecorderTest extends TestCase
{
    use BuildsInvoiceFixtures;
    use RefreshDatabase;

    private Invoice $series;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-06-15');
        $this->buildFixtures();
        $this->series = $this->invoice();
        app(InvoiceForecastWriter::class)->writeAllocations($this->series, $this->managementGroup, '2026-01-01', '2026-06-01', [
            new CostAllocation(60, $this->interest), new CostAllocation(40, $this->repayment),
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function recorder(): InvoiceBookingRecorder
    {
        return app(InvoiceBookingRecorder::class);
    }

    private function book(string $date, float $interest, float $repayment): void
    {
        $this->recorder()->record($this->series, $this->managementGroup, InvoiceBookingRecorder::anchorOf($this->series->fresh()), $date, [
            new CostAllocation($interest, $this->interest), new CostAllocation($repayment, $this->repayment),
        ]);
    }

    // KI-generiert
    public function test_the_anchor_falls_back_from_recurrence_to_booking_and_invoice_date(): void
    {
        $this->assertSame('2026-01-01', InvoiceBookingRecorder::anchorOf($this->series->fresh()));
        $this->assertSame('2026-02-02', InvoiceBookingRecorder::anchorOf(new Invoice(['invoice_date' => '2026-01-05', 'booking_date' => '2026-02-02'])));
        $this->assertSame('2026-01-05', InvoiceBookingRecorder::anchorOf(new Invoice(['invoice_date' => '2026-01-05'])));
    }

    // KI-generiert
    public function test_a_booking_upgrades_the_open_row_of_its_nearest_term(): void
    {
        $this->book('2026-02-27', 59.5, 40.5);

        $march = Cost::whereDate('period_date', '2026-03-01')->where('cost_category_id', $this->interest->id)->sole();
        $this->assertSame(['2026-02-27', 59.5], [$march->booking_date->toDateString(), (float) $march->amount]);
        $this->assertSame(12, Cost::count());
    }

    // KI-generiert
    public function test_booking_the_same_date_again_changes_nothing(): void
    {
        $this->book('2026-02-01', 59.5, 40.5);
        $this->book('2026-02-01', 1, 1);

        $this->assertSame(59.5, (float) Cost::whereNotNull('booking_date')->where('cost_category_id', $this->interest->id)->sole()->amount);
    }

    // KI-generiert
    public function test_a_booking_without_a_forecast_row_creates_one(): void
    {
        $this->book('2025-11-30', 70, 30);

        $created = Cost::whereDate('period_date', '2025-12-01')->get();
        $this->assertCount(2, $created);
        $this->assertSame('2025-12-31', $created->first()->billingInterval->end_date->toDateString());
    }

    // KI-generiert
    public function test_a_pinned_share_only_matches_rows_of_its_interval(): void
    {
        $other = $this->interval('2020-01-01', '2020-12-31');
        $this->recorder()->record($this->series, $this->managementGroup, '2026-01-01', '2026-02-01', [
            new CostAllocation(10, $this->interest, $other),
        ]);

        $this->assertSame(1, Cost::where('billing_interval_id', $other->id)->count());
        $this->assertSame(13, Cost::count());
    }
}
