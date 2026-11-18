<?php

// KI-generiert

namespace Tests\Unit;

use App\Models\Lease;
use App\Models\ManagementGroup;
use App\Models\RentalUnit;
use App\Models\RentPayment;
use App\Models\User;
use App\Services\RentPaymentBooker;
use App\Services\RentPaymentGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class RentPaymentBookerTest extends TestCase
{
    use RefreshDatabase;

    private RentalUnit $rentalUnit;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-04-20');
        $user = User::factory()->create();
        $managementGroup = ManagementGroup::create(['description' => 'VG', 'user_group_id' => $user->groups()->first()->id]);
        $unit = $managementGroup->managementUnits()->create(['street' => 'S', 'postal_code' => '1', 'city' => 'O']);
        $this->rentalUnit = $unit->rentalUnits()->create(['type' => 'Wohnung', 'size' => 50, 'is_relevant_for_billing' => true]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function lease(string $start = '2026-01-01', array $attributes = []): Lease
    {
        return Lease::create([
            'rental_unit_id' => $this->rentalUnit->id, 'start_date' => $start,
            'rent_adjustment_type' => 'fixed', 'cold_rent' => 500, ...$attributes,
        ]);
    }

    /**
     * @return array<string, array{string, int, string}>
     */
    public static function periods(): array
    {
        return [
            'pünktlich am Ersten' => ['2026-02-01', 1, '2026-02-01'],
            'früh für den Folgemonat' => ['2026-01-28', 1, '2026-02-01'],
            'mitten im Monat' => ['2026-01-14', 1, '2026-01-01'],
            'Zahltag am 10.' => ['2026-01-20', 10, '2026-01-01'],
            'kurzer Monat' => ['2026-02-27', 31, '2026-02-01'],
        ];
    }

    // KI-generiert
    #[DataProvider('periods')]
    public function test_the_half_month_rule_decides_the_paid_month(string $date, int $day, string $expected): void
    {
        $this->assertSame($expected, (new RentPaymentBooker(app(RentPaymentGenerator::class)))->periodFor($date, $day)->toDateString());
    }

    // KI-generiert
    public function test_book_all_books_the_open_payment_of_each_month(): void
    {
        $lease = $this->lease();
        $booked = app(RentPaymentBooker::class)->bookAll($lease, ['2025-12-30', '2026-03-02']);

        $this->assertSame('2026-01-01', $booked['2025-12-30']->period_date->toDateString());
        $this->assertSame('2026-03-02', RentPayment::whereDate('period_date', '2026-03-01')->sole()->booking_date->toDateString());
        $this->assertSame(4, RentPayment::count());
    }

    // KI-generiert
    public function test_dates_outside_the_lease_or_already_booked_stay_unbooked(): void
    {
        $lease = $this->lease();
        $booker = app(RentPaymentBooker::class);
        $booker->bookAll($lease, ['2026-02-01']);

        $result = $booker->bookAll($lease, ['2026-02-01', '2026-02-02', '2025-06-01']);

        $this->assertSame('2026-02-01', $result['2026-02-01']->booking_date->toDateString());
        $this->assertNull($result['2026-02-02']);
        $this->assertNull($result['2025-06-01']);
        $this->assertSame('2026-02-01', RentPayment::whereDate('period_date', '2026-02-01')->sole()->booking_date->toDateString());
    }

    // KI-generiert
    public function test_a_coupled_lease_books_nothing_itself(): void
    {
        $main = $this->lease();
        $coupled = $this->lease('2026-01-01', ['coupled_to_lease_id' => $main->id]);

        $this->expectException(\InvalidArgumentException::class);
        app(RentPaymentBooker::class)->bookAll($coupled, ['2026-02-01']);
    }
}
