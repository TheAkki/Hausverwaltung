<?php

// KI-generiert

namespace Tests\Unit\InvoiceSeries;

use App\Models\Lease;
use App\Models\RentalUnit;
use App\Services\InvoiceSeries\BillingIntervalLocator;
use App\Services\InvoiceSeries\CostableManagementGroup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class BillingIntervalLocatorTest extends TestCase
{
    use BuildsInvoiceFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildFixtures();
    }

    // KI-generiert
    public function test_an_existing_interval_is_found_even_with_a_time_component(): void
    {
        $interval = $this->managementGroup->billingIntervals()->create([
            'start_date' => Carbon::parse('2026-01-01 14:30'), 'end_date' => Carbon::parse('2026-12-31 14:30'),
        ]);

        $this->assertTrue($interval->is((new BillingIntervalLocator)->covering($this->managementGroup, '2026-01-01')));
        $this->assertTrue($interval->is((new BillingIntervalLocator)->covering($this->managementGroup, '2026-12-31')));
    }

    // KI-generiert
    public function test_without_any_interval_the_calendar_year_is_created(): void
    {
        $interval = (new BillingIntervalLocator)->covering($this->managementGroup, '2025-06-10');

        $this->assertSame(['2025-01-01', '2025-12-31'], [$interval->start_date->toDateString(), $interval->end_date->toDateString()]);
    }

    // KI-generiert
    public function test_the_sequence_is_extended_forwards_and_backwards(): void
    {
        $this->interval('2025-04-01', '2026-03-31');
        $locator = new BillingIntervalLocator;

        $later = $locator->covering($this->managementGroup, '2027-05-01');
        $earlier = $locator->covering($this->managementGroup, '2023-05-01');

        $this->assertSame(['2027-01-01', '2027-12-31'], [$later->start_date->toDateString(), $later->end_date->toDateString()]);
        $this->assertSame(['2023-04-01', '2024-03-31'], [$earlier->start_date->toDateString(), $earlier->end_date->toDateString()]);
        $this->assertSame(
            ['2023-04-01', '2024-04-01', '2025-04-01', '2026-04-01', '2027-01-01'],
            $this->managementGroup->billingIntervals()->orderBy('start_date')->get()->map(fn ($i) => $i->start_date->toDateString())->all()
        );
    }

    // KI-generiert
    public function test_every_costable_resolves_to_its_management_group(): void
    {
        $unit = $this->managementGroup->managementUnits()->create(['street' => 'S', 'postal_code' => '1', 'city' => 'O']);
        $rentalUnit = RentalUnit::create(['management_unit_id' => $unit->id, 'type' => 'Wohnung', 'size' => 50, 'is_relevant_for_billing' => true]);
        $lease = Lease::create(['rental_unit_id' => $rentalUnit->id, 'start_date' => '2026-01-01', 'rent_adjustment_type' => 'fixed', 'cold_rent' => 1]);

        foreach ([$this->managementGroup, $unit, $rentalUnit, $lease] as $costable) {
            $this->assertTrue($this->managementGroup->is(CostableManagementGroup::of($costable)));
        }

        $this->expectException(\InvalidArgumentException::class);
        CostableManagementGroup::of($this->issuer);
    }
}
