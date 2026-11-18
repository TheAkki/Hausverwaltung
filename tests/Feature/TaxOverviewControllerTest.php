<?php

namespace Tests\Feature;

use App\Models\CostCategory;
use App\Models\Invoice;
use App\Models\InvoiceIssuer;
use App\Models\Lease;
use App\Models\ManagementGroup;
use App\Models\SettlementClaim;
use App\Models\Tenant;
use App\Models\User;
use App\Services\InvoiceSeries\InvoiceSeriesService;
use App\Services\RentPaymentGenerator;
use Database\Seeders\CostCategorySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Covers the Steuerübersicht (Konzept.md "### Steuerübersicht"): the Mieteinnahmen-
 * Pivot grouped by Etage (with Nachzahlungen/Rückzahlungen via SettlementClaim) and
 * the Werbungskosten table (steuerrelevante Kostenkategorien, nur gebuchte Kosten).
 */
class TaxOverviewControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_page_shows_income_grouped_by_floor_with_settlement_claims_and_tax_relevant_expenses(): void
    {
        $this->seed(CostCategorySeeder::class);
        Carbon::setTestNow('2026-06-15');

        $user = User::factory()->create();
        $group = $user->groups()->first();
        $managementGroup = ManagementGroup::create(['description' => 'Steuer MG', 'user_group_id' => $group->id]);
        $managementUnit = $managementGroup->managementUnits()->create([
            'street' => 'Steuerweg 1', 'postal_code' => '11111', 'city' => 'Steuerstadt',
        ]);

        $erdgeschoss = $managementUnit->rentalUnits()->create([
            'type' => 'Wohnung', 'size' => 50, 'floor' => 0, 'description' => 'EG-Whg', 'is_relevant_for_billing' => true,
        ]);
        $ersteEtage = $managementUnit->rentalUnits()->create([
            'type' => 'Wohnung', 'size' => 60, 'floor' => 1, 'description' => '1.OG-Whg', 'is_relevant_for_billing' => true,
        ]);

        $tenantA = Tenant::create(['first_name' => 'Max', 'last_name' => 'Mustermann', 'street' => 'A', 'postal_code' => '1', 'city' => 'B']);
        $leaseA = Lease::create([
            'rental_unit_id' => $erdgeschoss->id, 'start_date' => '2026-01-01', 'rent_adjustment_type' => 'fixed', 'cold_rent' => 500,
        ]);
        $leaseA->tenants()->attach($tenantA->id);
        $leaseA->leaseIntervals()->first()->update(['rent_amount' => 500, 'utility_advance_payment' => 100]);

        $tenantB = Tenant::create(['first_name' => 'Erika', 'last_name' => 'Beispiel', 'street' => 'A', 'postal_code' => '1', 'city' => 'B']);
        $leaseB = Lease::create([
            'rental_unit_id' => $ersteEtage->id, 'start_date' => '2026-01-01', 'rent_adjustment_type' => 'fixed', 'cold_rent' => 600,
        ]);
        $leaseB->tenants()->attach($tenantB->id);
        $leaseB->leaseIntervals()->first()->update(['rent_amount' => 600, 'utility_advance_payment' => 120]);

        foreach ([$leaseA, $leaseB] as $lease) {
            app(RentPaymentGenerator::class)->generate($lease->leaseIntervals->first());
            $lease->leaseIntervals->first()->rentPayments()
                ->whereDate('period_date', '2026-01-01')
                ->first()
                ->update(['booking_date' => '2026-01-03']);
        }

        $billingInterval = $managementGroup->billingIntervals()->first();
        SettlementClaim::create([
            'lease_id' => $leaseA->id, 'billing_interval_id' => $billingInterval->id,
            'amount' => 200, 'date' => '2026-03-01', 'booking_date' => '2026-03-10',
        ]);
        SettlementClaim::create([
            'lease_id' => $leaseB->id, 'billing_interval_id' => $billingInterval->id,
            'amount' => -50, 'date' => '2026-03-01', 'booking_date' => '2026-03-15',
        ]);
        // Zuflussprinzip: erst 2025 gebucht (nicht nur ein anderes "Datum") – darf bei
        // der Anzeige für 2026 nicht mitzählen.
        SettlementClaim::create([
            'lease_id' => $leaseA->id, 'billing_interval_id' => $billingInterval->id,
            'amount' => 999, 'date' => '2025-03-01', 'booking_date' => '2025-03-10',
        ]);
        // Noch offen (kein Buchungsdatum) – zählt für kein Jahr mit.
        SettlementClaim::create([
            'lease_id' => $leaseB->id, 'billing_interval_id' => $billingInterval->id,
            'amount' => 777, 'date' => '2026-03-01',
        ]);

        $issuer = InvoiceIssuer::create(['name' => 'Stadtwerke', 'street' => 'A', 'postal_code' => '1', 'city' => 'B']);
        $taxRelevantCategory = CostCategory::where('name', 'Heizkosten')->first();
        $nonTaxCategory = CostCategory::where('name', 'Wärmekosten')->first();
        $nonTaxCategory->update(['is_tax_relevant' => false]);

        $bookedInvoice = Invoice::create([
            'invoice_issuer_id' => $issuer->id, 'amount' => 300, 'invoice_date' => '2026-02-01',
            'booking_date' => '2026-02-01', 'periodicity' => 'once',
        ]);
        app(InvoiceSeriesService::class)->write($bookedInvoice, $managementUnit, $taxRelevantCategory);

        $nonTaxInvoice = Invoice::create([
            'invoice_issuer_id' => $issuer->id, 'amount' => 400, 'invoice_date' => '2026-02-01',
            'booking_date' => '2026-02-01', 'periodicity' => 'once',
        ]);
        app(InvoiceSeriesService::class)->write($nonTaxInvoice, $managementUnit, $nonTaxCategory);

        $unbookedInvoice = Invoice::create([
            'invoice_issuer_id' => $issuer->id, 'amount' => 500, 'invoice_date' => '2026-02-01', 'periodicity' => 'once',
        ]);
        app(InvoiceSeriesService::class)->write($unbookedInvoice, $managementUnit, $taxRelevantCategory);

        $response = $this->actingAs($user)->get(route('management-groups.tax-overview', $managementGroup).'?year=2026');

        $response->assertOk();
        $response->assertSee('Erdgeschoss');
        $response->assertSee('1. OG');
        // Mieteinnahmen-Summe: 500 (A) + 600 (B) = 1100.
        $response->assertSee(number_format(1100, 2, ',', '.'));
        // Betriebskostenvorauszahlung-Summe: 100 + 120 = 220.
        $response->assertSee(number_format(220, 2, ',', '.'));
        // Nachzahlung/Rückzahlung-Summe (nur 2026): 200 + (-50) = 150.
        $response->assertSee(number_format(150, 2, ',', '.'));

        $response->assertSee('Heizkosten');
        $response->assertDontSee('Wärmekosten');
        // Nur der gebuchte Betrag (300), nicht der unbebuchte (500).
        $response->assertSee(number_format(300, 2, ',', '.'));
    }

    public function test_a_user_cannot_view_the_tax_overview_of_a_management_group_they_cannot_access(): void
    {
        $user = User::factory()->create();
        $group = $user->groups()->first();
        $managementGroup = ManagementGroup::create(['description' => 'MG', 'user_group_id' => $group->id]);

        $outsider = User::factory()->create();

        $this->actingAs($outsider)->get(route('management-groups.tax-overview', $managementGroup))->assertForbidden();
    }
}
