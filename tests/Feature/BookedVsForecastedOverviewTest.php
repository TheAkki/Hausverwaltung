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

class BookedVsForecastedOverviewTest extends TestCase
{
    use RefreshDatabase;

    public function test_management_unit_page_shows_forecasted_and_booked_income_and_expenses_separately(): void
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

        // Book January's rent only.
        app(RentPaymentGenerator::class)->generate($lease->leaseIntervals->first());
        $lease->leaseIntervals->first()->rentPayments()
            ->whereDate('period_date', '2026-01-01')
            ->first()
            ->update(['booking_date' => '2026-01-05']);

        $issuer = InvoiceIssuer::create(['name' => 'Stadtwerke', 'street' => 'A', 'postal_code' => '1', 'city' => 'B']);
        $category = CostCategory::first();
        $invoice = Invoice::create([
            'invoice_issuer_id' => $issuer->id,
            'amount' => 100,
            'invoice_date' => '2026-02-01',
            'booking_date' => '2026-02-01',
            'periodicity' => 'once',
        ]);
        app(InvoiceSeriesService::class)->write($invoice, $managementUnit, $category);

        $response = $this->actingAs($user)->get(route('management-units.show', $managementUnit));

        $response->assertOk();
        $response->assertSee('Prognostizierte Mieteinnahmen');
        $response->assertSee('Gebuchte Mieteinnahmen');
        $response->assertSee('Prognostizierte Ausgaben');
        $response->assertSee('Gebuchte Ausgaben');

        Carbon::setTestNow();
    }

    public function test_expense_category_rows_can_be_expanded_to_show_their_underlying_rechnungen(): void
    {
        $this->seed(CostCategorySeeder::class);

        $user = User::factory()->create();
        $group = $user->groups()->first();

        $managementGroup = ManagementGroup::create(['description' => 'MG', 'user_group_id' => $group->id]);
        $managementUnit = $managementGroup->managementUnits()->create([
            'street' => 'Teststr 1', 'postal_code' => '11111', 'city' => 'Teststadt',
        ]);

        $issuerA = InvoiceIssuer::create(['name' => 'Stadtwerke', 'street' => 'A', 'postal_code' => '1', 'city' => 'B']);
        $issuerB = InvoiceIssuer::create(['name' => 'Wasserwerk', 'street' => 'A', 'postal_code' => '1', 'city' => 'B']);
        $category = CostCategory::first();
        $invoiceA = Invoice::create([
            'invoice_issuer_id' => $issuerA->id,
            'amount' => 100,
            'invoice_date' => '2026-02-01',
            'booking_date' => '2026-02-01',
            'periodicity' => 'once',
            'description' => 'Vertrag A',
        ]);
        $invoiceB = Invoice::create([
            'invoice_issuer_id' => $issuerB->id,
            'amount' => 40,
            'invoice_date' => '2026-04-01',
            'booking_date' => '2026-04-01',
            'periodicity' => 'once',
            'description' => 'Vertrag B',
        ]);
        app(InvoiceSeriesService::class)->write($invoiceA, $managementUnit, $category);
        app(InvoiceSeriesService::class)->write($invoiceB, $managementUnit, $category);

        $response = $this->actingAs($user)->get(route('management-units.show', $managementUnit).'?year=2026');

        $response->assertOk();
        // Kategoriezeile ist klickbar (Datenattribut fürs Aufklappen); die zunächst
        // verborgenen Detailzeilen listen die Summe je Rechnungssteller (+ Account),
        // aufgeschlüsselt nach Monaten wie die Kategoriezeile selbst.
        $response->assertSee('data-expense-toggle', false);
        $response->assertSee('Stadtwerke');
        $response->assertSee('Wasserwerk');
        // Die Detailzeilen-Beschriftung ist rechtsbündig.
        $this->assertMatchesRegularExpression(
            '/class="pl-8 pr-4 py-2 text-xs text-gray-500 text-right">Stadtwerke</',
            $response->getContent()
        );
    }

    /**
     * Regression test: the page includes financials/_expense-table.blade.php twice
     * (Prognostizierte/Gebuchte Ausgaben). Each inclusion's inline <script> used to query
     * `document.querySelectorAll('[data-expense-toggle]')` with no scoping – by the time
     * the *second* table's script ran, the *first* table's rows already existed in the DOM
     * too, so that script bound them a second, canceling-out click listener. A click on
     * the first ("Prognostiziert") table's category rows therefore visibly did nothing,
     * while the second ("Gebucht") table worked fine. Fix: each table gets its own
     * container id, and its script is scoped to just that container.
     */
    public function test_the_two_expense_tables_on_the_page_have_independently_scoped_toggle_scripts(): void
    {
        $this->seed(CostCategorySeeder::class);

        $user = User::factory()->create();
        $group = $user->groups()->first();

        $managementGroup = ManagementGroup::create(['description' => 'MG', 'user_group_id' => $group->id]);
        $managementUnit = $managementGroup->managementUnits()->create([
            'street' => 'Teststr 1', 'postal_code' => '11111', 'city' => 'Teststadt',
        ]);

        $issuer = InvoiceIssuer::create(['name' => 'Stadtwerke', 'street' => 'A', 'postal_code' => '1', 'city' => 'B']);
        $category = CostCategory::first();
        $invoice = Invoice::create([
            'invoice_issuer_id' => $issuer->id,
            'amount' => 100,
            'invoice_date' => '2026-02-01',
            'booking_date' => '2026-02-01',
            'periodicity' => 'once',
            'description' => 'Vertrag A',
        ]);
        app(InvoiceSeriesService::class)->write($invoice, $managementUnit, $category);

        $response = $this->actingAs($user)->get(route('management-units.show', $managementUnit).'?year=2026');
        $html = $response->getContent();

        preg_match_all('/id="(expense-table-\w+)"/', $html, $containerIds);
        preg_match_all("/querySelectorAll\('#(expense-table-\w+) \[data-expense-toggle\]'\)/", $html, $scopedQueries);

        // Genau zwei Tabellen (Prognostiziert/Gebucht), jede mit eigener, unterschiedlicher
        // Container-ID, und jedes Skript fragt exakt seinen eigenen Container ab.
        $this->assertCount(2, $containerIds[1]);
        $this->assertNotSame($containerIds[1][0], $containerIds[1][1]);
        $this->assertSame($containerIds[1], $scopedQueries[1]);
    }

    public function test_management_group_page_shows_forecasted_and_booked_income_and_expenses_separately(): void
    {
        $this->seed(CostCategorySeeder::class);

        $user = User::factory()->create();
        $group = $user->groups()->first();

        $managementGroup = ManagementGroup::create(['description' => 'MG', 'user_group_id' => $group->id]);
        $managementUnit = $managementGroup->managementUnits()->create([
            'street' => 'Teststr 1', 'postal_code' => '11111', 'city' => 'Teststadt',
        ]);
        $managementUnit->rentalUnits()->create([
            'type' => 'Wohnung', 'size' => 50, 'is_relevant_for_billing' => true,
        ]);

        $response = $this->actingAs($user)->get(route('management-groups.show', $managementGroup));

        $response->assertOk();
        $response->assertSee('Prognostizierte Einnahmen nach Mieteinheit-Typ');
        $response->assertSee('Gebuchte Einnahmen nach Mieteinheit-Typ');
        $response->assertSee('Prognostizierte Ausgaben');
        $response->assertSee('Gebuchte Ausgaben');
    }
}
