<?php

// KI-generiert

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Cost;
use App\Models\CostCategory;
use App\Models\Invoice;
use App\Models\InvoiceIssuerAccount;
use App\Models\ManagementGroup;
use App\Models\RentPayment;
use App\Models\User;
use Database\Seeders\CostCategorySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Api\Concerns\CreatesApiFixtures;
use Tests\TestCase;

/**
 * Abrechnungsintervall, Konto, Zuordnung und Kundenaccount einer Kostenzeile,
 * Mietzahlung bzw. Rechnung müssen zur selben Verwaltungsgruppe bzw. zum selben
 * Rechnungssteller gehören – eine fremde Id wird abgelehnt (ReferenceIntegrity).
 */
class ReferenceIntegrityTest extends TestCase
{
    use CreatesApiFixtures, RefreshDatabase;

    private User $user;

    private ManagementGroup $own;

    private ManagementGroup $foreign;

    private Invoice $invoice;

    private int $category;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CostCategorySeeder::class);
        $this->user = User::factory()->create();
        $this->own = $this->makeManagementGroup($this->user, kontosystem: true);
        $this->foreign = $this->makeManagementGroup(User::factory()->create(), kontosystem: true);
        $this->category = CostCategory::whereNull('user_group_id')->value('id');
        $this->invoice = Invoice::create([
            'invoice_issuer_id' => $this->makeIssuer($this->user)->id, 'amount' => 100, 'invoice_date' => '2026-01-01',
            'periodicity' => 'once', 'costable_type' => $this->own->getMorphClass(), 'costable_id' => $this->own->id,
        ]);
    }

    private function account(ManagementGroup $managementGroup): Account
    {
        return $managementGroup->accounts()->create(['name' => 'Konto', 'initial_balance' => 0, 'is_default' => true]);
    }

    /**
     * @return array<string, mixed>
     */
    private function costInput(array $overrides = []): array
    {
        return [
            'invoice_id' => $this->invoice->id, 'costable_type' => 'management_group', 'costable_id' => $this->own->id,
            'billing_interval_id' => $this->own->billingIntervals()->value('id'), 'cost_category_id' => $this->category,
            'amount' => 10, ...$overrides,
        ];
    }

    private function ownCost(): Cost
    {
        return $this->invoice->costs()->create([...$this->costInput(), 'period_date' => '2026-01-01']);
    }

    public function test_a_cost_on_a_foreign_management_group_is_forbidden(): void
    {
        $this->actingAs($this->user)->post(route('costs.store'), $this->costInput(['costable_id' => $this->foreign->id]))
            ->assertForbidden();

        Sanctum::actingAs($this->user);
        $this->postJson('/api/costs', $this->costInput(['costable_id' => $this->foreign->id]))->assertForbidden();
        $this->assertSame(0, Cost::count());
    }

    public function test_a_billing_interval_of_another_management_group_is_rejected(): void
    {
        $foreignInterval = $this->foreign->billingIntervals()->value('id');

        $this->actingAs($this->user)->post(route('costs.store'), $this->costInput(['billing_interval_id' => $foreignInterval]))
            ->assertSessionHasErrors('billing_interval_id');

        Sanctum::actingAs($this->user);
        $this->postJson('/api/costs', $this->costInput(['billing_interval_id' => $foreignInterval]))
            ->assertUnprocessable()->assertJsonValidationErrors('billing_interval_id');

        $cost = $this->ownCost();
        $this->putJson("/api/costs/{$cost->id}", ['billing_interval_id' => $foreignInterval, 'cost_category_id' => $this->category, 'amount' => 10])
            ->assertJsonValidationErrors('billing_interval_id');
        $this->actingAs($this->user)->post(route('costs.split', $cost), ['billing_interval_id' => $foreignInterval, 'amount' => 5])
            ->assertSessionHasErrors('billing_interval_id');
        $this->assertSame(1, Cost::count());
    }

    public function test_an_account_of_another_management_group_is_rejected_for_costs(): void
    {
        $foreignAccount = $this->account($this->foreign)->id;
        $ownAccount = $this->account($this->own)->id;

        $this->actingAs($this->user)->post(route('costs.store'), $this->costInput(['account_id' => $foreignAccount, 'booking_date' => '2026-01-02']))
            ->assertSessionHasErrors('account_id');

        Sanctum::actingAs($this->user);
        $cost = $this->ownCost();
        $this->putJson("/api/costs/{$cost->id}", [
            'billing_interval_id' => $cost->billing_interval_id, 'cost_category_id' => $this->category, 'amount' => 10, 'account_id' => $foreignAccount,
        ])->assertJsonValidationErrors('account_id');

        $this->putJson("/api/costs/{$cost->id}", [
            'billing_interval_id' => $cost->billing_interval_id, 'cost_category_id' => $this->category, 'amount' => 10, 'account_id' => $ownAccount,
        ])->assertOk();
        $this->assertSame([$ownAccount], $cost->accounts()->pluck('accounts.id')->all());
    }

    public function test_an_account_of_another_management_group_is_rejected_for_rent_payments(): void
    {
        $lease = $this->makeLease($this->user, $this->makeRentalUnit($this->user, managementUnit: $this->makeManagementUnit($this->user, $this->own)));
        $payment = RentPayment::create(['lease_interval_id' => $lease->leaseIntervals()->value('id'), 'period_date' => '2026-01-01', 'amount' => 500]);
        $foreignAccount = $this->account($this->foreign)->id;

        $this->actingAs($this->user)->put(route('rent-payments.update', $payment), ['booking_date' => '2026-01-02', 'account_id' => $foreignAccount])
            ->assertSessionHasErrors('account_id');

        Sanctum::actingAs($this->user);
        $this->putJson("/api/rent-payments/{$payment->id}", ['booking_date' => '2026-01-02', 'account_id' => $foreignAccount])
            ->assertJsonValidationErrors('account_id');
        $ownAccount = $this->account($this->own)->id;
        $this->putJson("/api/rent-payments/{$payment->id}", ['booking_date' => '2026-01-02', 'account_id' => $ownAccount])
            ->assertOk();
        $this->assertSame([$ownAccount], $payment->accounts()->pluck('accounts.id')->all());
    }

    public function test_a_customer_account_of_another_issuer_is_rejected(): void
    {
        $otherIssuer = $this->makeIssuer($this->user);
        $otherAccount = InvoiceIssuerAccount::create(['invoice_issuer_id' => $otherIssuer->id, 'customer_number' => 'FREMD-1']);
        $ownAccount = InvoiceIssuerAccount::create(['invoice_issuer_id' => $this->invoice->invoice_issuer_id, 'customer_number' => 'EIGEN-1']);

        $input = [
            'invoice_issuer_id' => $this->invoice->invoice_issuer_id, 'invoice_issuer_account_id' => $otherAccount->id,
            'amount' => 100, 'invoice_date' => '2026-01-01', 'periodicity' => 'once', 'costable' => 'management_group:'.$this->own->id,
            'billing_interval_id' => $this->own->billingIntervals()->value('id'), 'cost_category_id' => $this->category,
        ];

        $this->actingAs($this->user)->post(route('invoices.store'), $input)->assertSessionHasErrors('invoice_issuer_account_id');

        Sanctum::actingAs($this->user);
        $this->postJson('/api/invoices', $input)->assertJsonValidationErrors('invoice_issuer_account_id');
        $this->assertSame(1, Invoice::count());

        $this->invoice->update(['invoice_issuer_account_id' => $ownAccount->id]);
        $this->assertSame($ownAccount->id, $this->invoice->fresh()->invoice_issuer_account_id);
    }

    public function test_changing_the_issuer_drops_the_customer_account_of_the_old_one(): void
    {
        $ownAccount = InvoiceIssuerAccount::create(['invoice_issuer_id' => $this->invoice->invoice_issuer_id, 'customer_number' => 'EIGEN-1']);
        $this->invoice->update(['invoice_issuer_account_id' => $ownAccount->id]);
        $other = $this->makeIssuer($this->user);
        $input = ['invoice_issuer_id' => $other->id, 'amount' => 100, 'invoice_date' => '2026-01-01'];

        $this->actingAs($this->user)->put(route('invoices.update', $this->invoice), $input)->assertSessionHasNoErrors();
        $this->assertNull($this->invoice->fresh()->invoice_issuer_account_id);

        Invoice::whereKey($this->invoice->id)->update(['invoice_issuer_id' => $ownAccount->invoice_issuer_id, 'invoice_issuer_account_id' => $ownAccount->id]);
        Sanctum::actingAs($this->user);
        $this->putJson("/api/invoices/{$this->invoice->id}", $input)->assertOk()->assertJsonPath('invoice_issuer_account_id', null);
    }

    public function test_the_invoice_form_lists_only_customer_accounts_of_visible_issuers(): void
    {
        $stranger = User::factory()->create();
        InvoiceIssuerAccount::create(['invoice_issuer_id' => $this->makeIssuer($stranger)->id, 'customer_number' => 'GEHEIM-4711']);
        InvoiceIssuerAccount::create(['invoice_issuer_id' => $this->invoice->invoice_issuer_id, 'customer_number' => 'EIGEN-1']);

        $this->actingAs($this->user)->get(route('invoices.create'))
            ->assertOk()->assertSee('EIGEN-1')->assertDontSee('GEHEIM-4711');
        $this->actingAs($this->user)->get(route('invoices.edit', $this->invoice))
            ->assertOk()->assertDontSee('GEHEIM-4711');
    }
}
