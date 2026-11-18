<?php

namespace Tests\Feature;

use App\Models\Cost;
use App\Models\CostCategory;
use App\Models\Invoice;
use App\Models\InvoiceIssuer;
use App\Models\ManagementGroup;
use App\Models\User;
use Database\Seeders\CostCategorySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Covers CostController::split() (Konzept.md "### Rechnung": "Es muss möglich sein
 * eine Rechnung zu splitten. Das Splitten muss auch auf unterschiedliche
 * Abrechnungsintervalle sein.") – splitting off part of a Kostenzeile's amount into a
 * new one pinned to a different Abrechnungsintervall, without ever needing the two
 * resulting rows to individually satisfy CostSumWithinInvoiceAmount (their sum is
 * unchanged by construction).
 */
class CostSplitTest extends TestCase
{
    use RefreshDatabase;

    private function makeInvoiceWithCost(float $amount): array
    {
        $this->seed(CostCategorySeeder::class);

        $user = User::factory()->create();
        $group = $user->groups()->first();
        $managementGroup = ManagementGroup::create(['description' => 'Split Group', 'user_group_id' => $group->id]);
        $issuer = InvoiceIssuer::create(['user_group_id' => $group->id, 'name' => 'Stadtverwaltung', 'street' => 'A', 'postal_code' => '1', 'city' => 'B']);
        $category = CostCategory::first();

        $intervalA = $managementGroup->billingIntervals()->first();
        $intervalB = $managementGroup->billingIntervals()->create([
            'start_date' => $intervalA->start_date->copy()->addYear(),
            'end_date' => $intervalA->end_date->copy()->addYear(),
        ]);

        $invoice = Invoice::create([
            'invoice_issuer_id' => $issuer->id,
            'costable_type' => $managementGroup->getMorphClass(),
            'costable_id' => $managementGroup->id,
            'invoice_date' => $intervalA->end_date->toDateString(),
            'amount' => $amount,
            'periodicity' => 'once',
        ]);

        $cost = $invoice->costs()->create([
            'costable_type' => $managementGroup->getMorphClass(),
            'costable_id' => $managementGroup->id,
            'billing_interval_id' => $intervalA->id,
            'cost_category_id' => $category->id,
            'amount' => $amount,
            'period_date' => $intervalA->end_date->toDateString(),
            'booking_date' => $intervalA->end_date->toDateString(),
        ]);

        return [$user, $invoice, $cost, $intervalA, $intervalB, $managementGroup, $category];
    }

    public function test_splitting_a_cost_line_moves_part_of_its_amount_to_a_new_line_in_another_interval(): void
    {
        [$user, $invoice, $cost, $intervalA, $intervalB] = $this->makeInvoiceWithCost(1000);

        $response = $this->actingAs($user)->post(route('costs.split', $cost), [
            'billing_interval_id' => $intervalB->id,
            'amount' => 400,
        ]);

        $response->assertRedirect(route('invoices.show', $invoice));

        $cost->refresh();
        $this->assertSame(600.0, (float) $cost->amount);
        $this->assertSame($intervalA->id, $cost->billing_interval_id);

        $split = Cost::where('invoice_id', $invoice->id)->where('id', '!=', $cost->id)->first();
        $this->assertNotNull($split);
        $this->assertSame(400.0, (float) $split->amount);
        $this->assertSame($intervalB->id, $split->billing_interval_id);
        $this->assertSame($cost->cost_category_id, $split->cost_category_id);
        $this->assertSame($cost->period_date->toDateString(), $split->period_date->toDateString());
        $this->assertSame($cost->booking_date->toDateString(), $split->booking_date->toDateString());

        // The total stays exactly the invoice amount – the amount cap is satisfied by
        // construction, regardless of the two rows landing in different intervals.
        $this->assertSame(1000.0, (float) $invoice->costs()->sum('amount'));
    }

    public function test_splitting_off_the_full_amount_is_rejected(): void
    {
        [$user, $invoice, $cost, $intervalA, $intervalB] = $this->makeInvoiceWithCost(1000);

        $response = $this->actingAs($user)->post(route('costs.split', $cost), [
            'billing_interval_id' => $intervalB->id,
            'amount' => 1000,
        ]);

        $response->assertSessionHasErrors('amount');
        $this->assertSame(1000.0, (float) $cost->fresh()->amount);
        $this->assertSame(1, Cost::where('invoice_id', $invoice->id)->count());
    }

    public function test_splitting_into_the_same_billing_interval_is_rejected(): void
    {
        [$user, $invoice, $cost, $intervalA] = $this->makeInvoiceWithCost(1000);

        $response = $this->actingAs($user)->post(route('costs.split', $cost), [
            'billing_interval_id' => $intervalA->id,
            'amount' => 400,
        ]);

        $response->assertSessionHasErrors('billing_interval_id');
        $this->assertSame(1, Cost::where('invoice_id', $invoice->id)->count());
    }

    public function test_splitting_a_cost_line_linked_to_a_konto_carries_the_konto_over_with_its_own_amount(): void
    {
        [$user, $invoice, $cost, $intervalA, $intervalB, $managementGroup] = $this->makeInvoiceWithCost(1000);
        $account = $managementGroup->accounts()->create(['name' => 'Girokonto']);
        $cost->accounts()->attach($account->id, ['amount' => $cost->amount]);

        $this->actingAs($user)->post(route('costs.split', $cost), [
            'billing_interval_id' => $intervalB->id,
            'amount' => 400,
        ]);

        $cost->refresh();
        $this->assertTrue($cost->accounts->contains($account->id));
        $this->assertSame(600.0, (float) $cost->accounts->first()->pivot->amount);

        $split = Cost::where('invoice_id', $invoice->id)->where('id', '!=', $cost->id)->first();
        $this->assertTrue($split->accounts->contains($account->id));
        $this->assertSame(400.0, (float) $split->accounts->first()->pivot->amount);
    }

    // KI-generiert
    public function test_a_negative_refund_line_can_be_split_into_two_negative_lines(): void
    {
        [$user, $invoice, $cost, , $intervalB] = $this->makeInvoiceWithCost(-1000);

        $this->actingAs($user)->post(route('costs.split', $cost), [
            'billing_interval_id' => $intervalB->id,
            'amount' => -400,
        ])->assertRedirect(route('invoices.show', $invoice));

        $this->assertSame(-600.0, (float) $cost->fresh()->amount);
        $this->assertSame(-1000.0, (float) $invoice->costs()->sum('amount'));
    }

    // KI-generiert
    public function test_a_negative_refund_line_cannot_be_split_off_with_a_positive_amount(): void
    {
        [$user, $invoice, $cost, , $intervalB] = $this->makeInvoiceWithCost(-1000);

        $this->actingAs($user)->post(route('costs.split', $cost), [
            'billing_interval_id' => $intervalB->id,
            'amount' => 400,
        ])->assertSessionHasErrors('amount');

        $this->assertSame(1, Cost::where('invoice_id', $invoice->id)->count());
    }

    // KI-generiert
    public function test_splitting_off_the_full_negative_amount_is_rejected(): void
    {
        [$user, $invoice, $cost, , $intervalB] = $this->makeInvoiceWithCost(-1000);

        $this->actingAs($user)->post(route('costs.split', $cost), [
            'billing_interval_id' => $intervalB->id,
            'amount' => -1000,
        ])->assertSessionHasErrors('amount');

        $this->assertSame(-1000.0, (float) $cost->fresh()->amount);
    }
}
