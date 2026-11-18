<?php

namespace Tests\Unit;

use App\Models\Cost;
use App\Models\CostCategory;
use App\Models\Invoice;
use App\Models\InvoiceIssuer;
use App\Models\ManagementGroup;
use App\Models\User;
use Database\Seeders\CostCategorySeeder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Covers Cost::costableLabel() – mirrors Invoice::costableLabel() (InvoiceModelTest),
 * but for the Cost's own costable_type/costable_id, which can differ from its parent
 * Invoice's (a "Kostenzeile hinzufügen" can assign a cost to a different costable, e.g.
 * splitting a Sammelrechnung across several Mieteinheiten).
 */
class CostModelTest extends TestCase
{
    use RefreshDatabase;

    private function makeManagementGroup(): ManagementGroup
    {
        $user = User::factory()->create();
        $group = $user->groups()->first();

        return ManagementGroup::create(['description' => 'MG', 'user_group_id' => $group->id]);
    }

    private function makeCostFor(Model $costable, ManagementGroup $managementGroup): Cost
    {
        $this->seed(CostCategorySeeder::class);

        $issuer = InvoiceIssuer::create(['name' => 'Stadtwerke', 'street' => 'A', 'postal_code' => '1', 'city' => 'B']);
        $invoice = Invoice::create([
            'invoice_issuer_id' => $issuer->id,
            'costable_type' => $costable->getMorphClass(),
            'costable_id' => $costable->id,
            'amount' => 100,
            'invoice_date' => '2026-06-15',
            'periodicity' => 'once',
        ]);

        return Cost::create([
            'invoice_id' => $invoice->id,
            'costable_type' => $costable->getMorphClass(),
            'costable_id' => $costable->id,
            'billing_interval_id' => $managementGroup->billingIntervals()->first()->id,
            'cost_category_id' => CostCategory::first()->id,
            'amount' => 100,
            'period_date' => '2026-06-15',
        ]);
    }

    /**
     * Regression guard: a Cost booked against a RentalUnit used to show up in the
     * Kosten-table's "Zuordnung" column as a bare "RentalUnit #<id>"
     * (class_basename($cost->costable)."#{$cost->costable_id}"), instead of a
     * meaningful label like "Garage".
     */
    public function test_a_rental_units_type_is_used_as_its_label(): void
    {
        $managementGroup = $this->makeManagementGroup();
        $managementUnit = $managementGroup->managementUnits()->create([
            'street' => 'Teststr 1', 'postal_code' => '1', 'city' => 'B',
        ]);
        $rentalUnit = $managementUnit->rentalUnits()->create(['type' => 'Garage', 'is_relevant_for_billing' => false]);

        $cost = $this->makeCostFor($rentalUnit, $managementGroup);

        $this->assertSame('Mieteinheit: Garage', $cost->costableLabel());
    }

    public function test_the_management_group_itself_is_used_as_its_own_label(): void
    {
        $managementGroup = $this->makeManagementGroup();

        $cost = $this->makeCostFor($managementGroup, $managementGroup);

        $this->assertSame('Verwaltungsgruppe: MG', $cost->costableLabel());
    }
}
