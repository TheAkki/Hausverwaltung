<?php

// KI-generiert

namespace Tests\Unit\InvoiceSeries;

use App\Models\Invoice;
use App\Services\InvoiceSeries\OccurrenceSchedule;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class OccurrenceScheduleTest extends TestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // KI-generiert
    public function test_a_one_off_invoice_has_exactly_its_anchor_date(): void
    {
        $schedule = new OccurrenceSchedule(Invoice::PERIODICITY_ONCE);

        $this->assertFalse($schedule->isRecurring());
        $this->assertSame(['2026-03-05'], $schedule->dates('2026-03-05', '2027-01-01'));
        $this->assertSame('2026-03-05', $schedule->periodDate('2026-03-05', 4));
        $this->assertSame(0, $schedule->nearestIndex('2026-03-05', '2026-09-01'));
    }

    // KI-generiert
    public function test_monthly_dates_clamp_short_months_without_drifting(): void
    {
        $schedule = new OccurrenceSchedule(Invoice::PERIODICITY_MONTHLY);

        $this->assertSame(
            ['2026-01-31', '2026-02-28', '2026-03-31', '2026-04-30'],
            $schedule->dates('2026-01-31', '2026-04-30')
        );
    }

    // KI-generiert
    public function test_open_ended_series_run_to_the_end_of_the_next_year(): void
    {
        Carbon::setTestNow('2026-06-15');
        $dates = (new OccurrenceSchedule(Invoice::PERIODICITY_YEARLY))->dates('2025-02-01', null);

        $this->assertSame(['2025-02-01', '2026-02-01', '2027-02-01'], $dates);
    }

    /**
     * @return array<string, array{string, string, string}>
     */
    public static function steps(): array
    {
        return [
            'wöchentlich' => [Invoice::PERIODICITY_WEEKLY, '2026-01-01', '2026-01-15'],
            'zweiwöchentlich' => [Invoice::PERIODICITY_BIWEEKLY, '2026-01-01', '2026-01-29'],
            'quartalsweise' => [Invoice::PERIODICITY_QUARTERLY, '2026-01-01', '2026-07-01'],
            'jährlich' => [Invoice::PERIODICITY_YEARLY, '2026-01-01', '2028-01-01'],
        ];
    }

    // KI-generiert
    #[DataProvider('steps')]
    public function test_the_second_occurrence_follows_the_periodicity(string $periodicity, string $anchor, string $expected): void
    {
        $this->assertSame($expected, (new OccurrenceSchedule($periodicity))->periodDate($anchor, 2));
    }

    // KI-generiert
    public function test_a_booking_snaps_to_the_calendar_nearest_occurrence(): void
    {
        $schedule = new OccurrenceSchedule(Invoice::PERIODICITY_MONTHLY);

        $this->assertSame(1, $schedule->nearestIndex('2026-01-01', '2026-01-30'));
        $this->assertSame(1, $schedule->nearestIndex('2026-01-01', '2026-02-03'));
        $this->assertSame(2, $schedule->nearestIndex('2026-01-01', '2026-03-01'));
    }

    // KI-generiert
    public function test_bookings_before_the_anchor_count_backwards(): void
    {
        $schedule = new OccurrenceSchedule(Invoice::PERIODICITY_MONTHLY);

        $this->assertSame(-2, $schedule->nearestIndex('2026-03-01', '2026-01-02'));
        $this->assertSame('2026-01-01', $schedule->periodDate('2026-03-01', -2));
    }

    // KI-generiert
    public function test_for_reads_the_periodicity_of_an_invoice(): void
    {
        $this->assertTrue(OccurrenceSchedule::for(new Invoice(['periodicity' => Invoice::PERIODICITY_MONTHLY]))->isRecurring());
        $this->assertFalse(OccurrenceSchedule::for(new Invoice(['periodicity' => Invoice::PERIODICITY_MANUAL]))->isRecurring());
    }
}
