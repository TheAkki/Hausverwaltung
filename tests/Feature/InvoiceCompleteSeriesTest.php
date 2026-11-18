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
use Illuminate\Support\Carbon;
use Tests\TestCase;

class InvoiceCompleteSeriesTest extends TestCase
{
    use RefreshDatabase;

    private function makeMonthlyInvoiceWithOneCost(): array
    {
        $this->seed(CostCategorySeeder::class);

        $user = User::factory()->create();
        $group = $user->groups()->first();

        $managementGroup = ManagementGroup::create(['description' => 'MG', 'user_group_id' => $group->id]);
        $managementUnit = $managementGroup->managementUnits()->create([
            'street' => 'Teststr 1', 'postal_code' => '11111', 'city' => 'Teststadt',
        ]);
        $issuer = InvoiceIssuer::create(['name' => 'F&M', 'street' => 'A', 'postal_code' => '1', 'city' => 'B']);
        $category = CostCategory::where('name', 'Gebäudereinigung & Ungezieferbekämpfung')->first();

        $invoice = Invoice::create([
            'invoice_issuer_id' => $issuer->id,
            'costable_type' => $managementUnit->getMorphClass(),
            'costable_id' => $managementUnit->id,
            'invoice_date' => '2026-01-12',
            'booking_date' => '2026-01-05',
            'amount' => 368.84,
            'periodicity' => 'monthly',
        ]);

        $billingInterval = $managementGroup->billingIntervals()->first();

        // Mirrors what a user does today: manually add exactly one Kostenzeile for
        // the first month via the "Kostenzeile hinzufügen" form.
        $invoice->costs()->create([
            'costable_type' => $managementUnit->getMorphClass(),
            'costable_id' => $managementUnit->id,
            'billing_interval_id' => $billingInterval->id,
            'cost_category_id' => $category->id,
            'amount' => 368.84,
            'period_date' => '2026-01-05',
        ]);

        return [$user, $invoice, $category];
    }

    public function test_completing_the_series_fills_in_missing_months_without_touching_existing_ones(): void
    {
        Carbon::setTestNow('2026-04-15');
        [$user, $invoice, $category] = $this->makeMonthlyInvoiceWithOneCost();

        $response = $this->actingAs($user)->post(route('invoices.complete-series', $invoice), [
            'cost_category_id' => $category->id,
        ]);

        $response->assertRedirect(route('invoices.show', $invoice));

        // Ohne Ende reicht die Serie bis zum Ende des Folgejahres (Jan 2026 … Dez 2027).
        $costs = Cost::where('invoice_id', $invoice->id)->orderBy('period_date')->get();
        $this->assertCount(24, $costs);
        $this->assertSame(
            ['2026-01-05', '2026-02-05', '2026-03-05', '2026-04-05'],
            $costs->take(4)->map(fn ($c) => $c->period_date->toDateString())->all()
        );
        $this->assertSame('2027-12-05', $costs->last()->period_date->toDateString());

        // The pre-existing January row is untouched (still has no booking_date change).
        $january = $costs->first(fn ($c) => $c->period_date->toDateString() === '2026-01-05');
        $this->assertNotNull($january);
        $this->assertNull($january->booking_date);

        // Newly generated rows also start unbooked, so each can be confirmed individually.
        $this->assertTrue($costs->every(fn ($c) => $c->isOpen()));

        Carbon::setTestNow();
    }

    public function test_completing_the_series_twice_does_not_create_duplicates(): void
    {
        Carbon::setTestNow('2026-04-15');
        [$user, $invoice, $category] = $this->makeMonthlyInvoiceWithOneCost();

        $this->actingAs($user)->post(route('invoices.complete-series', $invoice), [
            'cost_category_id' => $category->id,
        ]);
        $this->actingAs($user)->post(route('invoices.complete-series', $invoice), [
            'cost_category_id' => $category->id,
        ]);

        $this->assertSame(24, Cost::where('invoice_id', $invoice->id)->count());

        Carbon::setTestNow();
    }

    public function test_an_invoice_without_a_costable_cannot_complete_a_series(): void
    {
        $this->seed(CostCategorySeeder::class);
        $user = User::factory()->create();
        $issuer = InvoiceIssuer::create(['name' => 'Ohne Zuordnung', 'street' => 'A', 'postal_code' => '1', 'city' => 'B']);
        $category = CostCategory::first();

        $invoice = Invoice::create([
            'invoice_issuer_id' => $issuer->id,
            'invoice_date' => '2026-01-01',
            'amount' => 100,
            'periodicity' => 'monthly',
        ]);

        $response = $this->actingAs($user)->post(route('invoices.complete-series', $invoice), [
            'cost_category_id' => $category->id,
        ]);

        $response->assertStatus(422);
    }

    public function test_a_once_invoice_cannot_complete_a_series(): void
    {
        $this->seed(CostCategorySeeder::class);
        $user = User::factory()->create();
        $group = $user->groups()->first();
        $managementGroup = ManagementGroup::create(['description' => 'MG', 'user_group_id' => $group->id]);
        $managementUnit = $managementGroup->managementUnits()->create([
            'street' => 'Teststr 1', 'postal_code' => '11111', 'city' => 'Teststadt',
        ]);
        $issuer = InvoiceIssuer::create(['name' => 'Einmalig', 'street' => 'A', 'postal_code' => '1', 'city' => 'B']);
        $category = CostCategory::first();

        $invoice = Invoice::create([
            'invoice_issuer_id' => $issuer->id,
            'costable_type' => $managementUnit->getMorphClass(),
            'costable_id' => $managementUnit->id,
            'invoice_date' => '2026-01-01',
            'amount' => 100,
            'periodicity' => 'once',
        ]);

        $response = $this->actingAs($user)->post(route('invoices.complete-series', $invoice), [
            'cost_category_id' => $category->id,
        ]);

        $response->assertStatus(422);
    }

    // KI-generiert
    public function test_completing_until_a_date_before_the_start_is_rejected(): void
    {
        Carbon::setTestNow('2026-04-15');
        [$user, $invoice, $category] = $this->makeMonthlyInvoiceWithOneCost();

        $this->actingAs($user)->post(route('invoices.complete-series', $invoice), [
            'cost_category_id' => $category->id, 'until' => '2025-01-01',
        ])->assertSessionHasErrors('until');

        Carbon::setTestNow();
    }
}
