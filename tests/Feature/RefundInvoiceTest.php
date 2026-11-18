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

// KI-generiert
/**
 * Einnahmen als Rechnungen mit negativem Betrag (Rückzahlungen/Gutschriften, z. B.
 * Stromrückzahlung) über die Web-Oberfläche: Anlegen, Bearbeiten und Kostenzeilen
 * mit demselben (negativen) Vorzeichen.
 */
class RefundInvoiceTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private ManagementGroup $managementGroup;

    private InvoiceIssuer $issuer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CostCategorySeeder::class);

        $this->user = User::factory()->create();
        $group = $this->user->groups()->first();
        $this->managementGroup = ManagementGroup::create(['description' => 'MG', 'user_group_id' => $group->id]);
        $this->issuer = InvoiceIssuer::create([
            'user_group_id' => $group->id, 'name' => 'Stadtwerke', 'street' => 'A', 'postal_code' => '1', 'city' => 'B',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return [
            'invoice_issuer_id' => $this->issuer->id,
            'invoice_date' => '2026-03-01',
            'periodicity' => 'once',
            'amount' => -66.51,
            'description' => 'Stromrückzahlung',
            'costable' => "management_group:{$this->managementGroup->id}",
            'billing_interval_id' => $this->managementGroup->billingIntervals()->first()->id,
            'cost_category_id' => CostCategory::first()->id,
            ...$overrides,
        ];
    }

    private function makeRefund(float $amount = -100): Invoice
    {
        return Invoice::create([
            'invoice_issuer_id' => $this->issuer->id, 'amount' => $amount,
            'invoice_date' => '2026-03-01', 'periodicity' => 'once',
            'costable_type' => $this->managementGroup->getMorphClass(), 'costable_id' => $this->managementGroup->id,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function costPayload(Invoice $invoice, float $amount): array
    {
        return [
            'invoice_id' => $invoice->id,
            'costable_type' => 'management_group', 'costable_id' => $this->managementGroup->id,
            'billing_interval_id' => $this->managementGroup->billingIntervals()->first()->id,
            'cost_category_id' => CostCategory::first()->id,
            'amount' => $amount,
        ];
    }

    public function test_a_refund_can_be_created_and_gets_a_negative_cost_row(): void
    {
        $this->actingAs($this->user)->post(route('invoices.store'), $this->payload())
            ->assertSessionHasNoErrors();

        $invoice = Invoice::first();
        $this->assertEquals(-66.51, $invoice->amount);
        $this->assertEquals(-66.51, $invoice->costs()->sum('amount'));
    }

    public function test_a_manual_refund_sums_its_negative_occurrences(): void
    {
        $this->actingAs($this->user)->post(route('invoices.store'), $this->payload([
            'periodicity' => 'manual', 'amount' => null,
            'occurrences' => [
                ['occurrence_date' => '2026-03-01', 'amount' => -40],
                ['occurrence_date' => '2026-04-01', 'amount' => -20],
            ],
        ]))->assertSessionHasNoErrors();

        $this->assertEquals(-60, Invoice::first()->amount);
    }

    public function test_occurrences_with_mixed_signs_are_rejected(): void
    {
        $this->actingAs($this->user)->post(route('invoices.store'), $this->payload([
            'periodicity' => 'manual', 'amount' => null,
            'occurrences' => [
                ['occurrence_date' => '2026-03-01', 'amount' => -40],
                ['occurrence_date' => '2026-04-01', 'amount' => 20],
            ],
        ]))->assertSessionHasErrors('occurrences.1.amount');

        $this->assertSame(0, Invoice::count());
    }

    public function test_an_effective_amount_with_the_opposite_sign_is_rejected(): void
    {
        $this->actingAs($this->user)->post(route('invoices.store'), $this->payload(['effective_amount' => 50]))
            ->assertSessionHasErrors('effective_amount');
    }

    public function test_an_invoice_can_be_turned_into_a_refund_on_update(): void
    {
        $invoice = $this->makeRefund(100);

        $this->actingAs($this->user)->put(route('invoices.update', $invoice), [
            'invoice_issuer_id' => $this->issuer->id, 'amount' => -80, 'effective_amount' => -75,
            'invoice_date' => '2026-03-01',
        ])->assertSessionHasNoErrors();

        $invoice->refresh();
        $this->assertEquals(-80, $invoice->amount);
        $this->assertEquals(-75, $invoice->effective_amount);
    }

    public function test_an_update_rejects_an_effective_amount_with_the_opposite_sign(): void
    {
        $invoice = $this->makeRefund();

        $this->actingAs($this->user)->put(route('invoices.update', $invoice), [
            'invoice_issuer_id' => $this->issuer->id, 'amount' => -80, 'effective_amount' => 75,
            'invoice_date' => '2026-03-01',
        ])->assertSessionHasErrors('effective_amount');
    }

    public function test_a_refund_takes_negative_cost_rows_up_to_its_amount(): void
    {
        $invoice = $this->makeRefund();

        $this->actingAs($this->user)->post(route('costs.store'), $this->costPayload($invoice, -60))
            ->assertSessionHasNoErrors();
        $this->actingAs($this->user)->post(route('costs.store'), $this->costPayload($invoice, -50))
            ->assertSessionHasErrors('amount');

        $this->assertEquals(-60, $invoice->costs()->sum('amount'));
    }

    public function test_a_refund_rejects_a_positive_cost_row(): void
    {
        $invoice = $this->makeRefund();

        $this->actingAs($this->user)->post(route('costs.store'), $this->costPayload($invoice, 10))
            ->assertSessionHasErrors('amount');
    }

    public function test_a_negative_cost_row_can_be_updated(): void
    {
        $invoice = $this->makeRefund();
        $cost = $invoice->costs()->create([
            'costable_type' => $this->managementGroup->getMorphClass(), 'costable_id' => $this->managementGroup->id,
            'billing_interval_id' => $this->managementGroup->billingIntervals()->first()->id,
            'cost_category_id' => CostCategory::first()->id, 'amount' => -100, 'period_date' => '2026-03-01',
        ]);

        $this->actingAs($this->user)->put(route('costs.update', $cost), [
            'billing_interval_id' => $cost->billing_interval_id, 'cost_category_id' => $cost->cost_category_id,
            'amount' => -90,
        ])->assertSessionHasNoErrors();

        $this->assertEquals(-90, Cost::find($cost->id)->amount);
    }

    public function test_the_split_form_offers_negative_bounds_for_a_refund_row(): void
    {
        $invoice = $this->makeRefund();
        $this->managementGroup->billingIntervals()->create(['start_date' => '2027-01-01', 'end_date' => '2027-12-31']);
        $invoice->costs()->create([
            'costable_type' => $this->managementGroup->getMorphClass(), 'costable_id' => $this->managementGroup->id,
            'billing_interval_id' => $this->managementGroup->billingIntervals()->first()->id,
            'cost_category_id' => CostCategory::first()->id, 'amount' => -100, 'period_date' => '2026-03-01',
        ]);

        $this->actingAs($this->user)->get(route('invoices.show', $invoice))
            ->assertOk()->assertSee('min="-100" max="-0.01"', false);
    }
}
