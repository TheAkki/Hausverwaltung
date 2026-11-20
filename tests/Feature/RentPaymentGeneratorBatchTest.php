<?php

// KI-generiert

namespace Tests\Feature;

use App\Models\Lease;
use App\Models\ManagementGroup;
use App\Models\RentalUnit;
use App\Models\RentPayment;
use App\Models\Tenant;
use App\Models\User;
use App\Services\RentPaymentGenerator;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Die Übersichtsseiten erzeugen die Mietzahlungen bei jedem Aufruf. Sie sollen dabei
 * mit wenigen Abfragen auskommen und nichts schreiben, wenn sich nichts geändert hat.
 */
class RentPaymentGeneratorBatchTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private ManagementGroup $managementGroup;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-04-15');

        $this->user = User::factory()->create();
        $this->managementGroup = ManagementGroup::create([
            'description' => 'MG', 'user_group_id' => $this->user->groups()->first()->id, 'kontosystem_enabled' => true,
        ]);
        $this->managementGroup->accounts()->create(['name' => 'Girokonto', 'is_default' => true]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_a_second_run_without_changes_writes_nothing(): void
    {
        [$flat] = $this->flatWithGarage();
        $generator = app(RentPaymentGenerator::class);
        $generator->generate($flat->currentLeaseInterval());

        $writes = $this->countWrites(fn () => $generator->generate($flat->currentLeaseInterval()));

        $this->assertSame(0, $writes);
        $this->assertSame(4, RentPayment::where('lease_interval_id', $flat->currentLeaseInterval()->id)->count());
    }

    public function test_a_changed_coupled_rent_updates_the_breakdown_of_open_payments_only(): void
    {
        [$flat, $garage] = $this->flatWithGarage();
        $generator = app(RentPaymentGenerator::class);
        $payments = $generator->generate($flat->currentLeaseInterval());
        $payments->first()->update(['booking_date' => '2026-01-31']);

        $garage->currentLeaseInterval()->update(['rent_amount' => 45]);
        $generator->generate($flat->currentLeaseInterval()->fresh());

        $this->assertEqualsWithDelta(30.0, $this->contribution($payments->first(), $garage), 0.001);
        $this->assertEqualsWithDelta(45.0, $this->contribution($payments->last(), $garage), 0.001);
        $this->assertEqualsWithDelta(545.0, (float) $payments->last()->fresh()->amount, 0.001);
    }

    public function test_ending_the_coupled_lease_removes_it_from_later_open_payments(): void
    {
        [$flat, $garage] = $this->flatWithGarage();
        $generator = app(RentPaymentGenerator::class);
        $generator->generate($flat->currentLeaseInterval());

        $garage->currentLeaseInterval()->update(['end_date' => '2026-02-28']);
        $payments = $generator->generate($flat->currentLeaseInterval()->fresh());

        $this->assertSame([$flat->id, $garage->id], $payments->first()->contributingLeases()->orderBy('leases.id')->pluck('leases.id')->all());
        $this->assertSame([$flat->id], $payments->last()->contributingLeases()->pluck('leases.id')->all());
    }

    public function test_many_intervals_are_generated_together_and_get_their_payments_set(): void
    {
        [$flat, $garage] = $this->flatWithGarage();
        $intervals = collect([$flat->currentLeaseInterval(), $garage->currentLeaseInterval()]);

        $generated = app(RentPaymentGenerator::class)->generateMany($intervals->concat([$intervals->first()]));

        $this->assertSame([$intervals[0]->id, $intervals[1]->id], array_keys($generated));
        $this->assertCount(4, $generated[$intervals[0]->id]);
        foreach ($intervals as $interval) {
            $this->assertTrue($interval->relationLoaded('rentPayments'));
            $this->assertEqualsCanonicalizing(
                RentPayment::where('lease_interval_id', $interval->id)->pluck('id')->all(),
                $interval->rentPayments->modelKeys()
            );
        }
        $this->assertSame(1, $generated[$intervals[0]->id]->first()->accounts()->count());
    }

    public function test_the_relation_also_keeps_payments_outside_the_elapsed_months(): void
    {
        [$flat] = $this->flatWithGarage();
        $interval = $flat->currentLeaseInterval();
        $future = RentPayment::create(['lease_interval_id' => $interval->id, 'period_date' => '2026-09-01', 'amount' => 1]);

        $generated = app(RentPaymentGenerator::class)->generateMany(collect([$interval]));

        $this->assertNotContains($future->id, $generated[$interval->id]->pluck('id')->all());
        $this->assertContains($future->id, $interval->rentPayments->modelKeys());
    }

    public function test_an_empty_list_generates_nothing(): void
    {
        $this->assertSame([], app(RentPaymentGenerator::class)->generateMany(new EloquentCollection));
    }

    public function test_the_management_group_page_uses_few_queries(): void
    {
        for ($i = 0; $i < 3; $i++) {
            $this->flatWithGarage();
        }
        $this->actingAs($this->user)->get(route('management-groups.show', $this->managementGroup))->assertOk();

        $queries = 0;
        DB::listen(function () use (&$queries) {
            $queries++;
        });
        $this->actingAs($this->user)->get(route('management-groups.show', $this->managementGroup))->assertOk();
        $afterThree = $queries;

        $this->flatWithGarage();
        $this->flatWithGarage();
        $this->actingAs($this->user)->get(route('management-groups.show', $this->managementGroup))->assertOk();
        $queries = 0;
        $this->actingAs($this->user)->get(route('management-groups.show', $this->managementGroup))->assertOk();

        // Zwei weitere Wohnungen samt Stellplatz (vier Mieteinheiten) kosten höchstens
        // zwei Abfragen je Mieteinheit – nicht mehr mehrere je Monat und Mietintervall.
        $this->assertLessThanOrEqual($afterThree + 4 * 2, $queries);
    }

    /**
     * Wohnung ab Januar mit gekoppeltem Stellplatz (500 + 30 Euro).
     *
     * @return array{0: Lease, 1: Lease}
     */
    private function flatWithGarage(): array
    {
        $unit = $this->managementGroup->managementUnits()->first()
            ?? $this->managementGroup->managementUnits()->create(['street' => 'Teststr 1', 'postal_code' => '11111', 'city' => 'Teststadt']);
        $tenant = Tenant::create(['user_group_id' => $this->managementGroup->user_group_id, 'first_name' => 'Max', 'last_name' => 'Muster', 'street' => 'A', 'postal_code' => '1', 'city' => 'B']);

        $flat = $this->lease($unit->rentalUnits()->create(['type' => 'Wohnung', 'is_relevant_for_billing' => true]), 500, $tenant);
        $garage = $this->lease($unit->rentalUnits()->create(['type' => 'Garage', 'is_relevant_for_billing' => false]), 30, $tenant, $flat);

        return [$flat, $garage];
    }

    private function lease(RentalUnit $rentalUnit, float $rent, Tenant $tenant, ?Lease $coupledTo = null): Lease
    {
        $lease = Lease::create([
            'rental_unit_id' => $rentalUnit->id, 'coupled_to_lease_id' => $coupledTo?->id, 'start_date' => '2026-01-01',
            'rent_adjustment_type' => 'fixed', 'cold_rent' => $rent,
        ]);
        $lease->tenants()->attach($tenant->id);

        return $lease;
    }

    private function contribution(RentPayment $payment, Lease $lease): float
    {
        return (float) $payment->contributingLeases()->whereKey($lease->id)->first()->pivot->amount;
    }

    private function countWrites(callable $action): int
    {
        $writes = 0;
        DB::listen(function ($query) use (&$writes) {
            if (preg_match('/^\s*(insert|update|delete)/i', $query->sql)) {
                $writes++;
            }
        });
        $action();

        return $writes;
    }
}
