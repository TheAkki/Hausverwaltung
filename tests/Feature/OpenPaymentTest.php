<?php

namespace Tests\Feature;

use App\Models\CostCategory;
use App\Models\Invoice;
use App\Models\InvoiceIssuer;
use App\Models\Lease;
use App\Models\ManagementGroup;
use App\Models\Tenant;
use App\Models\User;
use App\Services\InvoiceSeries\InvoiceSeriesService;
use App\Services\RentPaymentGenerator;
use Database\Seeders\CostCategorySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class OpenPaymentTest extends TestCase
{
    use RefreshDatabase;

    public function test_open_payments_page_lists_unbooked_costs_and_rent_payments_up_to_today(): void
    {
        $this->seed(CostCategorySeeder::class);
        Carbon::setTestNow('2026-04-15');

        $user = User::factory()->create();
        $group = $user->groups()->first();

        $managementGroup = ManagementGroup::create(['description' => 'MG', 'user_group_id' => $group->id]);
        $managementUnit = $managementGroup->managementUnits()->create([
            'street' => 'Teststr 1', 'postal_code' => '11111', 'city' => 'Teststadt',
        ]);
        $rentalUnit = $managementUnit->rentalUnits()->create([
            'type' => 'Wohnung', 'size' => 50, 'is_relevant_for_billing' => true,
        ]);
        $tenant = Tenant::create(['first_name' => 'Max', 'last_name' => 'Mustermann', 'street' => 'A', 'postal_code' => '1', 'city' => 'B']);
        $lease = Lease::create([
            'rental_unit_id' => $rentalUnit->id, 'start_date' => '2026-01-01',
            'rent_adjustment_type' => 'fixed', 'cold_rent' => 500,
        ]);
        $lease->tenants()->attach($tenant->id);
        app(RentPaymentGenerator::class)->generate($lease->leaseIntervals->first());

        $issuer = InvoiceIssuer::create(['name' => 'Stadtwerke', 'street' => 'A', 'postal_code' => '1', 'city' => 'B']);
        $category = CostCategory::first();
        $invoice = Invoice::create([
            'invoice_issuer_id' => $issuer->id,
            'invoice_date' => '2026-02-01',
            'amount' => 100,
            'periodicity' => 'once',
        ]);
        app(InvoiceSeriesService::class)->write($invoice, $managementUnit, $category);

        // A future cost/payment (period after "today") must not show up as open yet.
        $futureInvoice = Invoice::create([
            'invoice_issuer_id' => $issuer->id,
            'invoice_date' => '2026-06-01',
            'amount' => 50,
            'periodicity' => 'once',
        ]);
        app(InvoiceSeriesService::class)->write($futureInvoice, $managementUnit, $category);

        $response = $this->actingAs($user)->get(route('open-payments.index'));

        $response->assertOk();
        $response->assertSee('Stadtwerke');
        $response->assertSee('Mustermann');
        $response->assertDontSee(euro(50).' €');

        Carbon::setTestNow();
    }

    public function test_booked_items_do_not_appear_as_open(): void
    {
        $this->seed(CostCategorySeeder::class);

        $user = User::factory()->create();
        $group = $user->groups()->first();

        $managementGroup = ManagementGroup::create(['description' => 'MG', 'user_group_id' => $group->id]);
        $managementUnit = $managementGroup->managementUnits()->create([
            'street' => 'Teststr 1', 'postal_code' => '11111', 'city' => 'Teststadt',
        ]);
        $issuer = InvoiceIssuer::create(['name' => 'Bereits Gebucht GmbH', 'street' => 'A', 'postal_code' => '1', 'city' => 'B']);
        $category = CostCategory::first();
        $invoice = Invoice::create([
            'invoice_issuer_id' => $issuer->id,
            'invoice_date' => '2026-01-01',
            'booking_date' => '2026-01-01',
            'amount' => 100,
            'periodicity' => 'once',
        ]);
        app(InvoiceSeriesService::class)->write($invoice, $managementUnit, $category);

        $response = $this->actingAs($user)->get(route('open-payments.index'));

        $response->assertOk();
        $response->assertDontSee('Bereits Gebucht GmbH');
    }

    /**
     * Konzept.md "### Mietvertrag": a referenzierender (gekoppelter) Mietvertrag (e.g.
     * ein Stellplatz gekoppelt an die Wohnung desselben Mieters) is never booked on its
     * own – its Mietzahlungen would otherwise sit "offen" forever and clutter this
     * overview. They must be excluded, not just permanently unbookable.
     */
    public function test_a_coupled_leases_rent_payments_do_not_appear_as_open(): void
    {
        $this->seed(CostCategorySeeder::class);
        Carbon::setTestNow('2026-04-15');

        $user = User::factory()->create();
        $group = $user->groups()->first();

        $managementGroup = ManagementGroup::create(['description' => 'MG', 'user_group_id' => $group->id]);
        $managementUnit = $managementGroup->managementUnits()->create([
            'street' => 'Teststr 1', 'postal_code' => '11111', 'city' => 'Teststadt',
        ]);
        $wohnung = $managementUnit->rentalUnits()->create(['type' => 'Wohnung', 'is_relevant_for_billing' => true]);
        $garage = $managementUnit->rentalUnits()->create(['type' => 'Garage', 'is_relevant_for_billing' => false]);
        $tenant = Tenant::create(['first_name' => 'Max', 'last_name' => 'Gekoppelt', 'street' => 'A', 'postal_code' => '1', 'city' => 'B']);

        $wohnungLease = Lease::create([
            'rental_unit_id' => $wohnung->id, 'start_date' => '2026-01-01',
            'rent_adjustment_type' => 'fixed', 'cold_rent' => 500,
        ]);
        $wohnungLease->tenants()->attach($tenant->id);

        $garageLease = Lease::create([
            'rental_unit_id' => $garage->id, 'coupled_to_lease_id' => $wohnungLease->id, 'start_date' => '2026-01-01',
            'rent_adjustment_type' => 'fixed', 'cold_rent' => 30,
        ]);
        $garageLease->tenants()->attach($tenant->id);
        app(RentPaymentGenerator::class)->generate($garageLease->leaseIntervals->first());

        $response = $this->actingAs($user)->get(route('open-payments.index'));

        $response->assertOk();
        $response->assertDontSee('Gekoppelt');

        Carbon::setTestNow();
    }
}
