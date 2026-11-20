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

class CostStoreTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_manually_added_cost_line_is_booked_on_the_buchungsdatum_not_the_rechnungsdatum(): void
    {
        $this->seed(CostCategorySeeder::class);

        $user = User::factory()->create();
        $group = $user->groups()->first();

        $managementGroup = ManagementGroup::create([
            'description' => 'Test Group',
            'user_group_id' => $group->id,
        ]);
        $managementUnit = $managementGroup->managementUnits()->create([
            'street' => 'Teststraße 1', 'postal_code' => '12345', 'city' => 'Teststadt',
        ]);
        $billingInterval = $managementGroup->billingIntervals()->first();
        $issuer = InvoiceIssuer::create(['name' => 'Versicherung', 'street' => 'A', 'postal_code' => '1', 'city' => 'B']);
        $category = CostCategory::where('name', 'Sach- und Haftpflichtversicherung')->first();

        $invoice = Invoice::create([
            'invoice_issuer_id' => $issuer->id,
            'costable_type' => $managementUnit->getMorphClass(),
            'costable_id' => $managementUnit->id,
            'invoice_date' => '2025-11-01',
            'booking_date' => '2026-01-02',
            'amount' => 3462.47,
            'periodicity' => 'once',
        ]);

        $response = $this->actingAs($user)->post('/costs', [
            'invoice_id' => $invoice->id,
            'costable_type' => 'management_unit',
            'costable_id' => $managementUnit->id,
            'billing_interval_id' => $billingInterval->id,
            'cost_category_id' => $category->id,
            'amount' => 3462.47,
        ]);

        $response->assertRedirect(route('invoices.show', $invoice));

        $cost = Cost::where('invoice_id', $invoice->id)->first();
        $this->assertNotNull($cost);
        $this->assertSame('2026-01-02', $cost->period_date->toDateString());
    }

    public function test_a_manually_added_cost_line_can_set_its_own_period_date_and_booking_date(): void
    {
        $this->seed(CostCategorySeeder::class);

        $user = User::factory()->create();
        $group = $user->groups()->first();

        $managementGroup = ManagementGroup::create(['description' => 'Loan Group', 'user_group_id' => $group->id]);
        $managementUnit = $managementGroup->managementUnits()->create([
            'street' => 'Teststraße 1', 'postal_code' => '12345', 'city' => 'Teststadt',
        ]);
        $billingInterval = $managementGroup->billingIntervals()->first();
        $issuer = InvoiceIssuer::create(['name' => 'Bank', 'street' => 'A', 'postal_code' => '1', 'city' => 'B']);
        $zinsen = CostCategory::where('name', 'Zinsen')->first();

        $invoice = Invoice::create([
            'invoice_issuer_id' => $issuer->id,
            'costable_type' => $managementUnit->getMorphClass(),
            'costable_id' => $managementUnit->id,
            'invoice_date' => '2026-03-01',
            'amount' => 1000,
            'periodicity' => 'monthly',
        ]);

        $response = $this->actingAs($user)->post('/costs', [
            'invoice_id' => $invoice->id,
            'costable_type' => 'management_unit',
            'costable_id' => $managementUnit->id,
            'billing_interval_id' => $billingInterval->id,
            'cost_category_id' => $zinsen->id,
            'amount' => 300,
            'period_date' => '2026-05-01',
            'booking_date' => '2026-05-03',
        ]);

        $response->assertRedirect(route('invoices.show', $invoice));

        $cost = Cost::where('invoice_id', $invoice->id)->first();
        $this->assertSame('2026-05-01', $cost->period_date->toDateString());
        $this->assertSame('2026-05-03', $cost->booking_date->toDateString());
        $this->assertFalse($cost->isOpen());
    }

    public function test_editing_one_months_cost_of_a_recurring_invoice_is_not_capped_by_other_months(): void
    {
        // Regression test: a recurring invoice legitimately has the same amount
        // booked once per period. The CostSumWithinInvoiceAmount cap must only
        // compare costs within the same period_date, not across the whole series -
        // otherwise editing any single month's row fails once enough months exist
        // to exceed the invoice's one-off amount.
        $this->seed(CostCategorySeeder::class);

        $user = User::factory()->create();
        $group = $user->groups()->first();

        $managementGroup = ManagementGroup::create(['description' => 'F&M Group', 'user_group_id' => $group->id]);
        $managementUnit = $managementGroup->managementUnits()->create([
            'street' => 'Teststraße 1', 'postal_code' => '12345', 'city' => 'Teststadt',
        ]);
        $billingInterval = $managementGroup->billingIntervals()->first();
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

        foreach (['2026-01-05', '2026-02-05', '2026-03-05', '2026-04-05'] as $periodDate) {
            $invoice->costs()->create([
                'costable_type' => $managementUnit->getMorphClass(),
                'costable_id' => $managementUnit->id,
                'billing_interval_id' => $billingInterval->id,
                'cost_category_id' => $category->id,
                'amount' => 368.84,
                'period_date' => $periodDate,
            ]);
        }

        $march = $invoice->costs()->whereDate('period_date', '2026-03-05')->first();

        $response = $this->actingAs($user)->put(route('costs.update', $march), [
            'billing_interval_id' => $billingInterval->id,
            'cost_category_id' => $category->id,
            'amount' => 368.84,
            'period_date' => '2026-03-05',
            'booking_date' => '2026-03-06',
        ]);

        $response->assertRedirect(route('invoices.show', $invoice));
        $response->assertSessionDoesntHaveErrors('amount');
        $this->assertSame('2026-03-06', $march->fresh()->booking_date->toDateString());
    }

    public function test_splitting_a_single_period_still_respects_the_per_period_cap(): void
    {
        $this->seed(CostCategorySeeder::class);

        $user = User::factory()->create();
        $group = $user->groups()->first();

        $managementGroup = ManagementGroup::create(['description' => 'Loan Group 2', 'user_group_id' => $group->id]);
        $managementUnit = $managementGroup->managementUnits()->create([
            'street' => 'Teststraße 1', 'postal_code' => '12345', 'city' => 'Teststadt',
        ]);
        $billingInterval = $managementGroup->billingIntervals()->first();
        $issuer = InvoiceIssuer::create(['name' => 'Bank', 'street' => 'A', 'postal_code' => '1', 'city' => 'B']);
        $zinsen = CostCategory::where('name', 'Zinsen')->first();
        $tilgung = CostCategory::where('name', 'Tilgung')->first();

        $invoice = Invoice::create([
            'invoice_issuer_id' => $issuer->id,
            'costable_type' => $managementUnit->getMorphClass(),
            'costable_id' => $managementUnit->id,
            'invoice_date' => '2026-03-01',
            'amount' => 1000,
            'periodicity' => 'monthly',
        ]);

        $invoice->costs()->create([
            'costable_type' => $managementUnit->getMorphClass(),
            'costable_id' => $managementUnit->id,
            'billing_interval_id' => $billingInterval->id,
            'cost_category_id' => $zinsen->id,
            'amount' => 700,
            'period_date' => '2026-05-01',
        ]);

        // Adding Tilgung for the SAME period, exceeding the 1000 cap, must still fail.
        $response = $this->actingAs($user)->post('/costs', [
            'invoice_id' => $invoice->id,
            'costable_type' => 'management_unit',
            'costable_id' => $managementUnit->id,
            'billing_interval_id' => $billingInterval->id,
            'cost_category_id' => $tilgung->id,
            'amount' => 400,
            'period_date' => '2026-05-01',
        ]);

        $response->assertSessionHasErrors('amount');
    }

    /**
     * Regression: splitting an invoice into cost lines whose amounts sum exactly to
     * the invoice amount on paper (2358.38 + 836.22 = 3194.60) used to be rejected –
     * floating-point addition lands a hair above the cap (3194.6000000000004), and
     * the cap check compared with a strict ">" with no rounding tolerance.
     */
    public function test_splitting_into_amounts_that_sum_exactly_to_the_cap_is_not_rejected_by_float_imprecision(): void
    {
        $this->seed(CostCategorySeeder::class);

        $user = User::factory()->create();
        $group = $user->groups()->first();

        $managementGroup = ManagementGroup::create(['description' => 'Float Precision Group', 'user_group_id' => $group->id]);
        $managementUnit = $managementGroup->managementUnits()->create([
            'street' => 'Teststraße 1', 'postal_code' => '12345', 'city' => 'Teststadt',
        ]);
        $billingInterval = $managementGroup->billingIntervals()->first();
        $issuer = InvoiceIssuer::create(['name' => 'Stadtwerke', 'street' => 'A', 'postal_code' => '1', 'city' => 'B']);
        $heating = CostCategory::where('name', 'Heizkosten')->first();
        $water = CostCategory::where('name', 'Wasserversorgung')->first();

        $invoice = Invoice::create([
            'invoice_issuer_id' => $issuer->id,
            'costable_type' => $managementUnit->getMorphClass(),
            'costable_id' => $managementUnit->id,
            'invoice_date' => '2025-10-09',
            'amount' => 3194.60,
            'periodicity' => 'once',
        ]);

        $invoice->costs()->create([
            'costable_type' => $managementUnit->getMorphClass(),
            'costable_id' => $managementUnit->id,
            'billing_interval_id' => $billingInterval->id,
            'cost_category_id' => $heating->id,
            'amount' => 2358.38,
            'period_date' => '2025-10-09',
        ]);

        $response = $this->actingAs($user)->post('/costs', [
            'invoice_id' => $invoice->id,
            'costable_type' => 'management_unit',
            'costable_id' => $managementUnit->id,
            'billing_interval_id' => $billingInterval->id,
            'cost_category_id' => $water->id,
            'amount' => 836.22,
            'period_date' => '2025-10-09',
        ]);

        $response->assertSessionDoesntHaveErrors('amount');
        $this->assertSame(2, $invoice->costs()->count());
    }

    public function test_disabling_the_amount_cap_allows_exceeding_the_invoice_amount_for_a_period(): void
    {
        $this->seed(CostCategorySeeder::class);

        $user = User::factory()->create();
        $group = $user->groups()->first();

        $managementGroup = ManagementGroup::create(['description' => 'Loan Group 3', 'user_group_id' => $group->id]);
        $managementUnit = $managementGroup->managementUnits()->create([
            'street' => 'Teststraße 1', 'postal_code' => '12345', 'city' => 'Teststadt',
        ]);
        $billingInterval = $managementGroup->billingIntervals()->first();
        $issuer = InvoiceIssuer::create(['name' => 'Bank', 'street' => 'A', 'postal_code' => '1', 'city' => 'B']);
        $zinsen = CostCategory::where('name', 'Zinsen')->first();
        $tilgung = CostCategory::where('name', 'Tilgung')->first();

        $invoice = Invoice::create([
            'invoice_issuer_id' => $issuer->id,
            'costable_type' => $managementUnit->getMorphClass(),
            'costable_id' => $managementUnit->id,
            'invoice_date' => '2026-03-01',
            'amount' => 1000,
            'periodicity' => 'monthly',
            'disable_amount_cap' => true,
        ]);

        $invoice->costs()->create([
            'costable_type' => $managementUnit->getMorphClass(),
            'costable_id' => $managementUnit->id,
            'billing_interval_id' => $billingInterval->id,
            'cost_category_id' => $zinsen->id,
            'amount' => 700,
            'period_date' => '2026-05-01',
        ]);

        // Same period, would exceed the 1000 cap, but the cap is disabled for this invoice.
        $response = $this->actingAs($user)->post('/costs', [
            'invoice_id' => $invoice->id,
            'costable_type' => 'management_unit',
            'costable_id' => $managementUnit->id,
            'billing_interval_id' => $billingInterval->id,
            'cost_category_id' => $tilgung->id,
            'amount' => 400,
            'period_date' => '2026-05-01',
        ]);

        $response->assertRedirect(route('invoices.show', $invoice));
        $response->assertSessionDoesntHaveErrors('amount');
        $this->assertSame(1100.0, (float) $invoice->costs()->whereDate('period_date', '2026-05-01')->sum('amount'));
    }

    public function test_the_amount_cap_checkbox_can_be_toggled_via_the_edit_form(): void
    {
        $this->seed(CostCategorySeeder::class);

        $user = User::factory()->create();
        $group = $user->groups()->first();
        $managementGroup = ManagementGroup::create(['description' => 'Toggle Group', 'user_group_id' => $group->id]);
        $issuer = InvoiceIssuer::create(['user_group_id' => $group->id, 'name' => 'Bank', 'street' => 'A', 'postal_code' => '1', 'city' => 'B']);

        $invoice = Invoice::create([
            'invoice_issuer_id' => $issuer->id,
            'costable_type' => $managementGroup->getMorphClass(),
            'costable_id' => $managementGroup->id,
            'invoice_date' => '2026-03-01',
            'amount' => 1000,
            'periodicity' => 'once',
        ]);
        $this->assertFalse($invoice->fresh()->disable_amount_cap);

        $response = $this->actingAs($user)->put(route('invoices.update', $invoice), [
            'invoice_issuer_id' => $issuer->id,
            'invoice_date' => '2026-03-01',
            'amount' => 1000,
            'disable_amount_cap' => '1',
        ]);

        $response->assertRedirect(route('invoices.show', $invoice));
        $this->assertTrue($invoice->fresh()->disable_amount_cap);
    }

    public function test_the_add_cost_line_form_defaults_booking_date_to_the_invoices_own_for_once_invoices(): void
    {
        $this->seed(CostCategorySeeder::class);

        $user = User::factory()->create();
        $group = $user->groups()->first();

        $managementGroup = ManagementGroup::create(['description' => 'Concordia Group', 'user_group_id' => $group->id]);
        $managementUnit = $managementGroup->managementUnits()->create([
            'street' => 'Teststraße 1', 'postal_code' => '12345', 'city' => 'Teststadt',
        ]);
        $issuer = InvoiceIssuer::create(['name' => 'Concordia', 'street' => 'A', 'postal_code' => '1', 'city' => 'B']);

        $invoice = Invoice::create([
            'invoice_issuer_id' => $issuer->id,
            'costable_type' => $managementUnit->getMorphClass(),
            'costable_id' => $managementUnit->id,
            'invoice_date' => '2025-11-01',
            'booking_date' => '2026-01-02',
            'amount' => 3462.47,
            'periodicity' => 'once',
        ]);

        $response = $this->actingAs($user)->get(route('invoices.show', $invoice));

        $response->assertOk();
        // The booking_date input for the "Kostenzeile hinzufügen" form should be
        // pre-filled with the invoice's own Buchungsdatum for a once-off invoice.
        $response->assertSee('id="new_booking_date"', false);
        $response->assertSee('value="2026-01-02"', false);
    }

    public function test_the_add_cost_line_form_leaves_booking_date_blank_for_recurring_invoices(): void
    {
        $this->seed(CostCategorySeeder::class);

        $user = User::factory()->create();
        $group = $user->groups()->first();

        $managementGroup = ManagementGroup::create(['description' => 'F&M Group 2', 'user_group_id' => $group->id]);
        $managementUnit = $managementGroup->managementUnits()->create([
            'street' => 'Teststraße 1', 'postal_code' => '12345', 'city' => 'Teststadt',
        ]);
        $issuer = InvoiceIssuer::create(['name' => 'F&M', 'street' => 'A', 'postal_code' => '1', 'city' => 'B']);

        $invoice = Invoice::create([
            'invoice_issuer_id' => $issuer->id,
            'costable_type' => $managementUnit->getMorphClass(),
            'costable_id' => $managementUnit->id,
            'invoice_date' => '2026-01-12',
            'booking_date' => '2026-01-05',
            'amount' => 368.84,
            'periodicity' => 'monthly',
        ]);

        $response = $this->actingAs($user)->get(route('invoices.show', $invoice));

        $response->assertOk();
        $response->assertSee('id="new_booking_date"', false);
        $this->assertMatchesRegularExpression(
            '/id="new_booking_date"[^>]*value=""/',
            $response->getContent()
        );
    }

    public function test_a_cost_line_can_be_assigned_to_a_konto_when_the_kontosystem_is_enabled(): void
    {
        $this->seed(CostCategorySeeder::class);

        $user = User::factory()->create();
        $group = $user->groups()->first();

        $managementGroup = ManagementGroup::create(['description' => 'Konto Group', 'user_group_id' => $group->id, 'kontosystem_enabled' => true]);
        $managementUnit = $managementGroup->managementUnits()->create([
            'street' => 'Teststraße 1', 'postal_code' => '12345', 'city' => 'Teststadt',
        ]);
        $account = $managementGroup->accounts()->create(['name' => 'Girokonto']);
        $billingInterval = $managementGroup->billingIntervals()->first();
        $issuer = InvoiceIssuer::create(['name' => 'Bank', 'street' => 'A', 'postal_code' => '1', 'city' => 'B']);
        $category = CostCategory::first();

        $invoice = Invoice::create([
            'invoice_issuer_id' => $issuer->id,
            'costable_type' => $managementUnit->getMorphClass(),
            'costable_id' => $managementUnit->id,
            'invoice_date' => '2026-03-01',
            'amount' => 300,
            'periodicity' => 'once',
        ]);

        $response = $this->actingAs($user)->post('/costs', [
            'invoice_id' => $invoice->id,
            'costable_type' => 'management_unit',
            'costable_id' => $managementUnit->id,
            'billing_interval_id' => $billingInterval->id,
            'cost_category_id' => $category->id,
            'amount' => 300,
            'account_id' => $account->id,
        ]);

        $response->assertRedirect(route('invoices.show', $invoice));

        $cost = Cost::where('invoice_id', $invoice->id)->first();
        $this->assertTrue($cost->accounts->contains($account->id));
        $this->assertSame(300.0, (float) $cost->accounts->first()->pivot->amount);
    }

    public function test_booking_a_cost_line_without_a_konto_is_rejected_when_the_kontosystem_is_enabled(): void
    {
        $this->seed(CostCategorySeeder::class);

        $user = User::factory()->create();
        $group = $user->groups()->first();

        $managementGroup = ManagementGroup::create(['description' => 'Konto Group', 'user_group_id' => $group->id, 'kontosystem_enabled' => true]);
        $managementUnit = $managementGroup->managementUnits()->create([
            'street' => 'Teststraße 1', 'postal_code' => '12345', 'city' => 'Teststadt',
        ]);
        $billingInterval = $managementGroup->billingIntervals()->first();
        $issuer = InvoiceIssuer::create(['name' => 'Bank', 'street' => 'A', 'postal_code' => '1', 'city' => 'B']);
        $category = CostCategory::first();

        $invoice = Invoice::create([
            'invoice_issuer_id' => $issuer->id,
            'costable_type' => $managementUnit->getMorphClass(),
            'costable_id' => $managementUnit->id,
            'invoice_date' => '2026-03-01',
            'amount' => 300,
            'periodicity' => 'once',
        ]);

        $response = $this->actingAs($user)->post('/costs', [
            'invoice_id' => $invoice->id,
            'costable_type' => 'management_unit',
            'costable_id' => $managementUnit->id,
            'billing_interval_id' => $billingInterval->id,
            'cost_category_id' => $category->id,
            'amount' => 300,
            'booking_date' => '2026-03-05',
        ]);

        $response->assertSessionHasErrors('account_id');
        $this->assertSame(0, Cost::where('invoice_id', $invoice->id)->count());
    }

    public function test_booking_a_cost_line_without_a_konto_is_allowed_when_the_kontosystem_is_disabled(): void
    {
        $this->seed(CostCategorySeeder::class);

        $user = User::factory()->create();
        $group = $user->groups()->first();

        $managementGroup = ManagementGroup::create(['description' => 'No Konto Group', 'user_group_id' => $group->id]);
        $managementUnit = $managementGroup->managementUnits()->create([
            'street' => 'Teststraße 1', 'postal_code' => '12345', 'city' => 'Teststadt',
        ]);
        $billingInterval = $managementGroup->billingIntervals()->first();
        $issuer = InvoiceIssuer::create(['name' => 'Bank', 'street' => 'A', 'postal_code' => '1', 'city' => 'B']);
        $category = CostCategory::first();

        $invoice = Invoice::create([
            'invoice_issuer_id' => $issuer->id,
            'costable_type' => $managementUnit->getMorphClass(),
            'costable_id' => $managementUnit->id,
            'invoice_date' => '2026-03-01',
            'amount' => 300,
            'periodicity' => 'once',
        ]);

        $response = $this->actingAs($user)->post('/costs', [
            'invoice_id' => $invoice->id,
            'costable_type' => 'management_unit',
            'costable_id' => $managementUnit->id,
            'billing_interval_id' => $billingInterval->id,
            'cost_category_id' => $category->id,
            'amount' => 300,
            'booking_date' => '2026-03-05',
        ]);

        $response->assertRedirect(route('invoices.show', $invoice));
        $this->assertSame(1, Cost::where('invoice_id', $invoice->id)->count());
    }

    public function test_a_cost_lines_konto_assignment_can_be_changed_via_update(): void
    {
        $this->seed(CostCategorySeeder::class);

        $user = User::factory()->create();
        $group = $user->groups()->first();

        $managementGroup = ManagementGroup::create(['description' => 'Konto Group 2', 'user_group_id' => $group->id, 'kontosystem_enabled' => true]);
        $managementUnit = $managementGroup->managementUnits()->create([
            'street' => 'Teststraße 1', 'postal_code' => '12345', 'city' => 'Teststadt',
        ]);
        $accountA = $managementGroup->accounts()->create(['name' => 'Girokonto']);
        $accountB = $managementGroup->accounts()->create(['name' => 'Rücklagenkonto']);
        $billingInterval = $managementGroup->billingIntervals()->first();
        $issuer = InvoiceIssuer::create(['name' => 'Bank', 'street' => 'A', 'postal_code' => '1', 'city' => 'B']);
        $category = CostCategory::first();

        $invoice = Invoice::create([
            'invoice_issuer_id' => $issuer->id,
            'costable_type' => $managementUnit->getMorphClass(),
            'costable_id' => $managementUnit->id,
            'invoice_date' => '2026-03-01',
            'amount' => 300,
            'periodicity' => 'once',
        ]);
        $cost = $invoice->costs()->create([
            'costable_type' => $managementUnit->getMorphClass(),
            'costable_id' => $managementUnit->id,
            'billing_interval_id' => $billingInterval->id,
            'cost_category_id' => $category->id,
            'amount' => 300,
            'period_date' => '2026-03-01',
        ]);
        $cost->accounts()->attach($accountA->id, ['amount' => 300]);

        $response = $this->actingAs($user)->put(route('costs.update', $cost), [
            'billing_interval_id' => $billingInterval->id,
            'cost_category_id' => $category->id,
            'amount' => 300,
            'account_id' => $accountB->id,
        ]);

        $response->assertRedirect(route('invoices.show', $invoice));

        $cost->refresh();
        $this->assertFalse($cost->accounts->contains($accountA->id));
        $this->assertTrue($cost->accounts->contains($accountB->id));
    }

    public function test_editing_an_unrelated_field_of_an_open_cost_line_does_not_require_a_konto(): void
    {
        $this->seed(CostCategorySeeder::class);

        $user = User::factory()->create();
        $group = $user->groups()->first();

        $managementGroup = ManagementGroup::create(['description' => 'Konto Group 3', 'user_group_id' => $group->id, 'kontosystem_enabled' => true]);
        $managementUnit = $managementGroup->managementUnits()->create([
            'street' => 'Teststraße 1', 'postal_code' => '12345', 'city' => 'Teststadt',
        ]);
        $billingInterval = $managementGroup->billingIntervals()->first();
        $issuer = InvoiceIssuer::create(['name' => 'Bank', 'street' => 'A', 'postal_code' => '1', 'city' => 'B']);
        $category = CostCategory::first();

        $invoice = Invoice::create([
            'invoice_issuer_id' => $issuer->id,
            'costable_type' => $managementUnit->getMorphClass(),
            'costable_id' => $managementUnit->id,
            'invoice_date' => '2026-03-01',
            'amount' => 300,
            'periodicity' => 'once',
        ]);
        $cost = $invoice->costs()->create([
            'costable_type' => $managementUnit->getMorphClass(),
            'costable_id' => $managementUnit->id,
            'billing_interval_id' => $billingInterval->id,
            'cost_category_id' => $category->id,
            'amount' => 300,
            'period_date' => '2026-03-01',
        ]);

        // The inline table submits the whole row (including the Buchungsdatum field's
        // placeholder value) whenever an unrelated field like Betrag changes on a still
        // open row — this must not be blocked by the "Konto required to book" rule, since
        // the user never clicked "Buchen" (no confirm_booking marker present).
        $otherCategory = CostCategory::where('id', '!=', $category->id)->first();

        $response = $this->actingAs($user)->put(route('costs.update', $cost), [
            'billing_interval_id' => $billingInterval->id,
            'cost_category_id' => $otherCategory->id,
            'amount' => 300,
            'period_date' => '2026-03-01',
            'booking_date' => '2026-03-01',
        ]);

        $response->assertRedirect(route('invoices.show', $invoice));
        $response->assertSessionDoesntHaveErrors('account_id');
        $this->assertSame($otherCategory->id, $cost->fresh()->cost_category_id);
    }

    public function test_clicking_buchen_on_a_cost_line_without_a_konto_is_rejected(): void
    {
        $this->seed(CostCategorySeeder::class);

        $user = User::factory()->create();
        $group = $user->groups()->first();

        $managementGroup = ManagementGroup::create(['description' => 'Konto Group 4', 'user_group_id' => $group->id, 'kontosystem_enabled' => true]);
        $managementUnit = $managementGroup->managementUnits()->create([
            'street' => 'Teststraße 1', 'postal_code' => '12345', 'city' => 'Teststadt',
        ]);
        $billingInterval = $managementGroup->billingIntervals()->first();
        $issuer = InvoiceIssuer::create(['name' => 'Bank', 'street' => 'A', 'postal_code' => '1', 'city' => 'B']);
        $category = CostCategory::first();

        $invoice = Invoice::create([
            'invoice_issuer_id' => $issuer->id,
            'costable_type' => $managementUnit->getMorphClass(),
            'costable_id' => $managementUnit->id,
            'invoice_date' => '2026-03-01',
            'amount' => 300,
            'periodicity' => 'once',
        ]);
        $cost = $invoice->costs()->create([
            'costable_type' => $managementUnit->getMorphClass(),
            'costable_id' => $managementUnit->id,
            'billing_interval_id' => $billingInterval->id,
            'cost_category_id' => $category->id,
            'amount' => 300,
            'period_date' => '2026-03-01',
        ]);

        $response = $this->actingAs($user)->put(route('costs.update', $cost), [
            'billing_interval_id' => $billingInterval->id,
            'cost_category_id' => $category->id,
            'amount' => 300,
            'period_date' => '2026-03-01',
            'booking_date' => '2026-03-01',
            'confirm_booking' => '1',
        ]);

        $response->assertSessionHasErrors('account_id');
        $this->assertTrue($cost->fresh()->isOpen());
    }
}
