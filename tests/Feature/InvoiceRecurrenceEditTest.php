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

class InvoiceRecurrenceEditTest extends TestCase
{
    use RefreshDatabase;

    public function test_recurrence_end_can_be_extended_via_the_edit_form_and_a_future_series_completed(): void
    {
        $this->seed(CostCategorySeeder::class);

        $user = User::factory()->create();
        $group = $user->groups()->first();

        $managementGroup = ManagementGroup::create(['description' => 'F&M Group', 'user_group_id' => $group->id]);
        $managementUnit = $managementGroup->managementUnits()->create([
            'street' => 'Teststraße 1', 'postal_code' => '12345', 'city' => 'Teststadt',
        ]);
        $issuer = InvoiceIssuer::create(['user_group_id' => $group->id, 'name' => 'F&M', 'street' => 'A', 'postal_code' => '1', 'city' => 'B']);
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
        $invoice->costs()->create([
            'costable_type' => $managementUnit->getMorphClass(),
            'costable_id' => $managementUnit->id,
            'billing_interval_id' => $managementGroup->billingIntervals()->first()->id,
            'cost_category_id' => $category->id,
            'amount' => 368.84,
            'period_date' => '2026-01-05',
        ]);

        $response = $this->actingAs($user)->put(route('invoices.update', $invoice), [
            'invoice_issuer_id' => $issuer->id,
            'invoice_date' => '2026-01-12',
            'amount' => 368.84,
            'recurrence_end' => '2026-12-31',
        ]);

        $response->assertRedirect(route('invoices.show', $invoice));
        $this->assertSame('2026-12-31', $invoice->fresh()->recurrence_end->toDateString());

        // With recurrence_end now extended, completing the series should reach
        // December even though "until" is left at its default (today).
        $this->actingAs($user)->post(route('invoices.complete-series', $invoice), [
            'cost_category_id' => $category->id,
        ]);

        $costs = Cost::where('invoice_id', $invoice->id)->orderBy('period_date')->get();
        $this->assertSame('2026-12-05', $costs->last()->period_date->toDateString());
        $this->assertCount(12, $costs);
    }
}
