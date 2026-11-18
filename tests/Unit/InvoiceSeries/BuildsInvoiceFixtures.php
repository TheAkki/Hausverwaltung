<?php

// KI-generiert

namespace Tests\Unit\InvoiceSeries;

use App\Models\BillingInterval;
use App\Models\CostCategory;
use App\Models\Invoice;
use App\Models\InvoiceIssuer;
use App\Models\ManagementGroup;
use App\Models\User;
use Database\Seeders\CostCategorySeeder;

/**
 * Gemeinsame Testdaten: eine Verwaltungsgruppe ohne automatisch angelegte Intervalle,
 * zwei Kategorien und ein Rechnungssteller.
 */
trait BuildsInvoiceFixtures
{
    private ManagementGroup $managementGroup;

    private CostCategory $interest;

    private CostCategory $repayment;

    private InvoiceIssuer $issuer;

    private function buildFixtures(): void
    {
        $this->seed(CostCategorySeeder::class);
        $user = User::factory()->create();
        $this->managementGroup = ManagementGroup::create(['description' => 'VG', 'user_group_id' => $user->groups()->first()->id]);
        $this->managementGroup->billingIntervals()->delete();
        $this->interest = CostCategory::where('name', 'Zinsen')->firstOrFail();
        $this->repayment = CostCategory::where('name', 'Tilgung')->firstOrFail();
        $this->issuer = InvoiceIssuer::create(['name' => 'Bank', 'street' => 'A', 'postal_code' => '1', 'city' => 'B']);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function invoice(array $attributes = []): Invoice
    {
        return Invoice::create([
            'invoice_issuer_id' => $this->issuer->id,
            'amount' => 100,
            'invoice_date' => '2026-01-01',
            'periodicity' => Invoice::PERIODICITY_MONTHLY,
            'costable_type' => $this->managementGroup->getMorphClass(),
            'costable_id' => $this->managementGroup->id,
            ...$attributes,
        ]);
    }

    private function interval(string $start, string $end): BillingInterval
    {
        return $this->managementGroup->billingIntervals()->create(['start_date' => $start, 'end_date' => $end]);
    }
}
