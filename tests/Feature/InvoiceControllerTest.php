<?php

namespace Tests\Feature;

use App\Models\CostCategory;
use App\Models\Invoice;
use App\Models\InvoiceIssuer;
use App\Models\Lease;
use App\Models\ManagementGroup;
use App\Models\RentalUnit;
use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\CostCategorySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Covers Invoice CRUD paths not already exercised by the more scenario-focused
 * Invoice*Test files: the create/edit form views, manual (händisch) periodicity
 * occurrences, all three costable types, destroy(), and the Kontosystem-enabled
 * account dropdown branch of formData().
 */
class InvoiceControllerTest extends TestCase
{
    use RefreshDatabase;

    private function makeManagementGroup(User $user, bool $kontosystem = false): ManagementGroup
    {
        $group = $user->groups()->first();

        return ManagementGroup::create([
            'description' => 'Test Group', 'user_group_id' => $group->id,
            'kontosystem_enabled' => $kontosystem,
        ]);
    }

    public function test_the_create_form_can_be_rendered(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get(route('invoices.create'));

        $response->assertOk();
    }

    public function test_the_show_page_lists_accounts_when_the_kontosystem_is_enabled(): void
    {
        $user = User::factory()->create();
        $group = $user->groups()->first();
        $managementGroup = $this->makeManagementGroup($user, kontosystem: true);
        $managementGroup->accounts()->create(['name' => 'Girokonto']);
        $issuer = InvoiceIssuer::create(['user_group_id' => $group->id, 'name' => 'Stadtwerke', 'street' => 'A', 'postal_code' => '1', 'city' => 'B']);
        $invoice = Invoice::create([
            'invoice_issuer_id' => $issuer->id, 'amount' => 100,
            'invoice_date' => '2026-01-01', 'periodicity' => 'once',
            'costable_type' => $managementGroup->getMorphClass(), 'costable_id' => $managementGroup->id,
        ]);

        $response = $this->actingAs($user)->get(route('invoices.show', $invoice));

        $response->assertOk();
        $response->assertSee('Girokonto');
    }

    public function test_a_manual_periodicity_invoice_sums_its_occurrences_as_the_amount(): void
    {
        $this->seed(CostCategorySeeder::class);
        $user = User::factory()->create();
        $group = $user->groups()->first();
        $managementGroup = $this->makeManagementGroup($user);
        $issuer = InvoiceIssuer::create(['user_group_id' => $group->id, 'name' => 'Stadtwerke', 'street' => 'A', 'postal_code' => '1', 'city' => 'B']);
        $category = CostCategory::first();
        $billingInterval = $managementGroup->billingIntervals()->first();

        $response = $this->actingAs($user)->post(route('invoices.store'), [
            'invoice_issuer_id' => $issuer->id,
            'invoice_date' => '2026-01-01',
            'periodicity' => 'manual',
            'occurrences' => [
                ['occurrence_date' => '2026-01-15', 'amount' => 40],
                ['occurrence_date' => '2026-02-15', 'amount' => 60],
            ],
            'costable' => "management_group:{$managementGroup->id}",
            'billing_interval_id' => $billingInterval->id,
            'cost_category_id' => $category->id,
        ]);

        $invoice = Invoice::first();
        $this->assertNotNull($invoice);
        $response->assertRedirect(route('invoices.show', $invoice));
        $this->assertEquals(100, $invoice->amount);
        $this->assertSame(2, $invoice->occurrences()->count());
    }

    public function test_a_manual_periodicity_invoice_without_occurrences_is_rejected(): void
    {
        $this->seed(CostCategorySeeder::class);
        $user = User::factory()->create();
        $group = $user->groups()->first();
        $managementGroup = $this->makeManagementGroup($user);
        $issuer = InvoiceIssuer::create(['user_group_id' => $group->id, 'name' => 'Stadtwerke', 'street' => 'A', 'postal_code' => '1', 'city' => 'B']);
        $category = CostCategory::first();
        $billingInterval = $managementGroup->billingIntervals()->first();

        $response = $this->actingAs($user)->post(route('invoices.store'), [
            'invoice_issuer_id' => $issuer->id,
            'invoice_date' => '2026-01-01',
            'periodicity' => 'manual',
            'occurrences' => [],
            'costable' => "management_group:{$managementGroup->id}",
            'billing_interval_id' => $billingInterval->id,
            'cost_category_id' => $category->id,
        ]);

        $response->assertSessionHasErrors('occurrences');
        $this->assertSame(0, Invoice::count());
    }

    public function test_an_invoice_can_be_created_against_a_management_group(): void
    {
        $this->seed(CostCategorySeeder::class);
        $user = User::factory()->create();
        $group = $user->groups()->first();
        $managementGroup = $this->makeManagementGroup($user);
        $issuer = InvoiceIssuer::create(['user_group_id' => $group->id, 'name' => 'Stadtwerke', 'street' => 'A', 'postal_code' => '1', 'city' => 'B']);
        $category = CostCategory::first();
        $billingInterval = $managementGroup->billingIntervals()->first();

        $response = $this->actingAs($user)->post(route('invoices.store'), [
            'invoice_issuer_id' => $issuer->id,
            'invoice_date' => '2026-01-01',
            'amount' => 100,
            'periodicity' => 'once',
            'costable' => "management_group:{$managementGroup->id}",
            'billing_interval_id' => $billingInterval->id,
            'cost_category_id' => $category->id,
        ]);

        $invoice = Invoice::first();
        $this->assertNotNull($invoice);
        $response->assertRedirect(route('invoices.show', $invoice));
        $this->assertTrue($invoice->costable->is($managementGroup));
    }

    /**
     * A CostCategory owned by a DIFFERENT Account's Benutzergruppe (guessable by id,
     * "cost_category_id" only checks existence, not visibility) must not be usable
     * here, even for a costable the user does own – see CostCategoryPolicy.
     */
    public function test_an_invoice_cannot_be_created_against_another_accounts_cost_category(): void
    {
        $user = User::factory()->create();
        $group = $user->groups()->first();
        $managementGroup = $this->makeManagementGroup($user);
        $issuer = InvoiceIssuer::create(['user_group_id' => $group->id, 'name' => 'Stadtwerke', 'street' => 'A', 'postal_code' => '1', 'city' => 'B']);
        $billingInterval = $managementGroup->billingIntervals()->first();

        $otherAccount = User::factory()->create();
        $foreignCategory = CostCategory::create([
            'user_group_id' => $otherAccount->groups()->first()->id,
            'name' => 'Fremde Kategorie',
            'is_relevant_for_billing' => true,
        ]);

        $response = $this->actingAs($user)->post(route('invoices.store'), [
            'invoice_issuer_id' => $issuer->id,
            'invoice_date' => '2026-01-01',
            'amount' => 100,
            'periodicity' => 'once',
            'costable' => "management_group:{$managementGroup->id}",
            'billing_interval_id' => $billingInterval->id,
            'cost_category_id' => $foreignCategory->id,
        ]);

        $response->assertForbidden();
        $this->assertNull(Invoice::first());
    }

    public function test_an_invoice_can_be_created_against_a_rental_unit(): void
    {
        $this->seed(CostCategorySeeder::class);
        $user = User::factory()->create();
        $group = $user->groups()->first();
        $managementGroup = $this->makeManagementGroup($user);
        $managementUnit = $managementGroup->managementUnits()->create(['street' => 'A', 'postal_code' => '1', 'city' => 'B']);
        $rentalUnit = $managementUnit->rentalUnits()->create(['type' => 'Wohnung', 'is_relevant_for_billing' => true]);
        $issuer = InvoiceIssuer::create(['user_group_id' => $group->id, 'name' => 'Stadtwerke', 'street' => 'A', 'postal_code' => '1', 'city' => 'B']);
        $category = CostCategory::first();
        $billingInterval = $managementGroup->billingIntervals()->first();

        $response = $this->actingAs($user)->post(route('invoices.store'), [
            'invoice_issuer_id' => $issuer->id,
            'invoice_date' => '2026-01-01',
            'amount' => 100,
            'periodicity' => 'once',
            'costable' => "rental_unit:{$rentalUnit->id}",
            'billing_interval_id' => $billingInterval->id,
            'cost_category_id' => $category->id,
        ]);

        $invoice = Invoice::first();
        $this->assertNotNull($invoice);
        $response->assertRedirect(route('invoices.show', $invoice));
        $this->assertTrue($invoice->costable->is(RentalUnit::find($rentalUnit->id)));
    }

    /**
     * Regression: creating a Rechnung directly against a Mietvertrag (offered in the
     * create form's dropdown as "lease:{id}", used e.g. for BKA-relevante Kosten die
     * einem einzelnen Mietvertrag zugeordnet sind) used to 404 – InvoiceController's
     * resolveCostable() and InvoiceCostGenerator's resolveManagementGroup() only
     * matched ManagementGroup/ManagementUnit/RentalUnit, never Lease.
     */
    public function test_an_invoice_can_be_created_against_a_lease(): void
    {
        $this->seed(CostCategorySeeder::class);
        $user = User::factory()->create();
        $group = $user->groups()->first();
        $managementGroup = $this->makeManagementGroup($user);
        $managementUnit = $managementGroup->managementUnits()->create(['street' => 'A', 'postal_code' => '1', 'city' => 'B']);
        $rentalUnit = $managementUnit->rentalUnits()->create(['type' => 'Wohnung', 'is_relevant_for_billing' => true]);
        $tenant = Tenant::create(['user_group_id' => $group->id, 'first_name' => 'Max', 'last_name' => 'Mustermann', 'street' => 'A', 'postal_code' => '1', 'city' => 'B']);
        $lease = Lease::create([
            'rental_unit_id' => $rentalUnit->id, 'start_date' => '2026-01-01',
            'rent_adjustment_type' => 'fixed', 'cold_rent' => 500,
        ]);
        $lease->tenants()->attach($tenant->id);
        $issuer = InvoiceIssuer::create(['user_group_id' => $group->id, 'name' => 'Stadtwerke', 'street' => 'A', 'postal_code' => '1', 'city' => 'B']);
        $category = CostCategory::first();
        $billingInterval = $managementGroup->billingIntervals()->first();

        $response = $this->actingAs($user)->post(route('invoices.store'), [
            'invoice_issuer_id' => $issuer->id,
            'invoice_date' => '2026-01-01',
            'amount' => 100,
            'periodicity' => 'once',
            'costable' => "lease:{$lease->id}",
            'billing_interval_id' => $billingInterval->id,
            'cost_category_id' => $category->id,
        ]);

        $invoice = Invoice::first();
        $this->assertNotNull($invoice);
        $response->assertRedirect(route('leases.show', $lease).'#rechnungen');
        $this->assertTrue($invoice->costable->is(Lease::find($lease->id)));

        $showResponse = $this->actingAs($user)->get(route('invoices.show', $invoice));
        $showResponse->assertOk();
        $showResponse->assertSee('Mietvertrag: Max Mustermann');
    }

    /**
     * The Lease detail page's "Rechnungen" section is the entry point for
     * anlegen/bearbeiten/löschen of a Mietvertrag-level Rechnung; opening
     * invoices.create with "lease_id" pre-selects the Mietvertrag in the costable
     * dropdown so the user doesn't have to find it again in the global list.
     */
    public function test_the_create_form_preselects_the_lease_when_opened_with_a_lease_id(): void
    {
        $user = User::factory()->create();
        $group = $user->groups()->first();
        $managementGroup = $this->makeManagementGroup($user);
        $managementUnit = $managementGroup->managementUnits()->create(['street' => 'A', 'postal_code' => '1', 'city' => 'B']);
        $rentalUnit = $managementUnit->rentalUnits()->create(['type' => 'Wohnung', 'is_relevant_for_billing' => true]);
        $tenant = Tenant::create(['user_group_id' => $group->id, 'first_name' => 'Max', 'last_name' => 'Mustermann', 'street' => 'A', 'postal_code' => '1', 'city' => 'B']);
        $lease = Lease::create([
            'rental_unit_id' => $rentalUnit->id, 'start_date' => '2026-01-01',
            'rent_adjustment_type' => 'fixed', 'cold_rent' => 500,
        ]);
        $lease->tenants()->attach($tenant->id);

        $response = $this->actingAs($user)->get(route('invoices.create', ['lease_id' => $lease->id]));

        $response->assertOk();
        $response->assertSee('value="lease:'.$lease->id.'" selected', false);
    }

    public function test_opening_the_create_form_with_a_lease_id_is_forbidden_for_an_outsider(): void
    {
        $owner = User::factory()->create();
        $group = $owner->groups()->first();
        $managementGroup = $this->makeManagementGroup($owner);
        $managementUnit = $managementGroup->managementUnits()->create(['street' => 'A', 'postal_code' => '1', 'city' => 'B']);
        $rentalUnit = $managementUnit->rentalUnits()->create(['type' => 'Wohnung', 'is_relevant_for_billing' => true]);
        $tenant = Tenant::create(['user_group_id' => $group->id, 'first_name' => 'Max', 'last_name' => 'Mustermann', 'street' => 'A', 'postal_code' => '1', 'city' => 'B']);
        $lease = Lease::create([
            'rental_unit_id' => $rentalUnit->id, 'start_date' => '2026-01-01',
            'rent_adjustment_type' => 'fixed', 'cold_rent' => 500,
        ]);
        $lease->tenants()->attach($tenant->id);
        $outsider = User::factory()->create();

        $response = $this->actingAs($outsider)->get(route('invoices.create', ['lease_id' => $lease->id]));

        $response->assertForbidden();
    }

    public function test_the_lease_show_page_lists_its_invoices(): void
    {
        $this->seed(CostCategorySeeder::class);
        $user = User::factory()->create();
        $group = $user->groups()->first();
        $managementGroup = $this->makeManagementGroup($user);
        $managementUnit = $managementGroup->managementUnits()->create(['street' => 'A', 'postal_code' => '1', 'city' => 'B']);
        $rentalUnit = $managementUnit->rentalUnits()->create(['type' => 'Wohnung', 'is_relevant_for_billing' => true]);
        $tenant = Tenant::create(['user_group_id' => $group->id, 'first_name' => 'Max', 'last_name' => 'Mustermann', 'street' => 'A', 'postal_code' => '1', 'city' => 'B']);
        $lease = Lease::create([
            'rental_unit_id' => $rentalUnit->id, 'start_date' => '2026-01-01',
            'rent_adjustment_type' => 'fixed', 'cold_rent' => 500,
        ]);
        $lease->tenants()->attach($tenant->id);
        $issuer = InvoiceIssuer::create(['user_group_id' => $group->id, 'name' => 'Stadtwerke', 'street' => 'A', 'postal_code' => '1', 'city' => 'B']);
        Invoice::create([
            'invoice_issuer_id' => $issuer->id, 'amount' => 250, 'description' => 'Heizung Nachberechnung',
            'invoice_date' => '2026-03-01', 'periodicity' => 'once',
            'costable_type' => $lease->getMorphClass(), 'costable_id' => $lease->id,
        ]);

        $response = $this->actingAs($user)->get(route('leases.show', $lease));

        $response->assertOk();
        $response->assertSee('Heizung Nachberechnung');
        $response->assertSee('Stadtwerke');
    }

    public function test_updating_a_lease_invoice_redirects_back_to_the_lease_page(): void
    {
        $user = User::factory()->create();
        $group = $user->groups()->first();
        $managementGroup = $this->makeManagementGroup($user);
        $managementUnit = $managementGroup->managementUnits()->create(['street' => 'A', 'postal_code' => '1', 'city' => 'B']);
        $rentalUnit = $managementUnit->rentalUnits()->create(['type' => 'Wohnung', 'is_relevant_for_billing' => true]);
        $tenant = Tenant::create(['user_group_id' => $group->id, 'first_name' => 'Max', 'last_name' => 'Mustermann', 'street' => 'A', 'postal_code' => '1', 'city' => 'B']);
        $lease = Lease::create([
            'rental_unit_id' => $rentalUnit->id, 'start_date' => '2026-01-01',
            'rent_adjustment_type' => 'fixed', 'cold_rent' => 500,
        ]);
        $lease->tenants()->attach($tenant->id);
        $issuer = InvoiceIssuer::create(['user_group_id' => $group->id, 'name' => 'Stadtwerke', 'street' => 'A', 'postal_code' => '1', 'city' => 'B']);
        $invoice = Invoice::create([
            'invoice_issuer_id' => $issuer->id, 'amount' => 100,
            'invoice_date' => '2026-01-01', 'periodicity' => 'once',
            'costable_type' => $lease->getMorphClass(), 'costable_id' => $lease->id,
        ]);

        $response = $this->actingAs($user)->put(route('invoices.update', $invoice), [
            'invoice_issuer_id' => $issuer->id,
            'amount' => 150,
            'invoice_date' => '2026-01-01',
        ]);

        $response->assertRedirect(route('leases.show', $lease).'#rechnungen');
        $this->assertEquals(150, $invoice->fresh()->amount);
    }

    public function test_the_edit_form_can_be_rendered(): void
    {
        $this->seed(CostCategorySeeder::class);
        $user = User::factory()->create();
        $group = $user->groups()->first();
        $managementGroup = $this->makeManagementGroup($user);
        $issuer = InvoiceIssuer::create(['user_group_id' => $group->id, 'name' => 'Stadtwerke', 'street' => 'A', 'postal_code' => '1', 'city' => 'B']);
        $invoice = Invoice::create([
            'invoice_issuer_id' => $issuer->id, 'amount' => 100,
            'invoice_date' => '2026-01-01', 'periodicity' => 'once',
            'costable_type' => $managementGroup->getMorphClass(), 'costable_id' => $managementGroup->id,
        ]);

        $response = $this->actingAs($user)->get(route('invoices.edit', $invoice));

        $response->assertOk();
    }

    /**
     * The "Kosten" section (existing cost lines editable inline, "Kostenzeile
     * hinzufügen" to split into more categories) used to exist only on the show
     * page – the edit page had no way to adjust an invoice's cost split at all.
     */
    public function test_the_edit_page_also_shows_the_costs_section_for_splitting(): void
    {
        $this->seed(CostCategorySeeder::class);
        $user = User::factory()->create();
        $group = $user->groups()->first();
        $managementGroup = $this->makeManagementGroup($user);
        $issuer = InvoiceIssuer::create(['user_group_id' => $group->id, 'name' => 'Stadtwerke', 'street' => 'A', 'postal_code' => '1', 'city' => 'B']);
        $category = CostCategory::first();
        $billingInterval = $managementGroup->billingIntervals()->first();
        $invoice = Invoice::create([
            'invoice_issuer_id' => $issuer->id, 'amount' => 100,
            'invoice_date' => '2026-01-01', 'periodicity' => 'once',
            'costable_type' => $managementGroup->getMorphClass(), 'costable_id' => $managementGroup->id,
        ]);
        $invoice->costs()->create([
            'costable_type' => $managementGroup->getMorphClass(), 'costable_id' => $managementGroup->id,
            'billing_interval_id' => $billingInterval->id, 'cost_category_id' => $category->id,
            'amount' => 100, 'period_date' => '2026-01-01',
        ]);

        $response = $this->actingAs($user)->get(route('invoices.edit', $invoice));

        $response->assertOk();
        $response->assertSee(__('Kosten'));
        $response->assertSee(__('Kostenzeile hinzufügen'));
    }

    public function test_the_index_hides_fully_booked_invoices_by_default_but_shows_open_ones(): void
    {
        $this->seed(CostCategorySeeder::class);
        $user = User::factory()->create();
        $group = $user->groups()->first();
        $managementGroup = $this->makeManagementGroup($user);
        $issuer = InvoiceIssuer::create(['user_group_id' => $group->id, 'name' => 'Stadtwerke', 'street' => 'A', 'postal_code' => '1', 'city' => 'B']);
        $category = CostCategory::first();
        $billingInterval = $managementGroup->billingIntervals()->first();

        $completedInvoice = Invoice::create([
            'invoice_issuer_id' => $issuer->id, 'amount' => 100, 'description' => 'Erledigte Rechnung',
            'invoice_date' => '2026-01-01', 'periodicity' => 'once',
            'costable_type' => $managementGroup->getMorphClass(), 'costable_id' => $managementGroup->id,
        ]);
        $completedInvoice->costs()->create([
            'costable_type' => $managementGroup->getMorphClass(), 'costable_id' => $managementGroup->id,
            'billing_interval_id' => $billingInterval->id, 'cost_category_id' => $category->id,
            'amount' => 100, 'period_date' => '2026-01-01', 'booking_date' => '2026-01-05',
        ]);

        $openInvoice = Invoice::create([
            'invoice_issuer_id' => $issuer->id, 'amount' => 50, 'description' => 'Offene Rechnung',
            'invoice_date' => '2026-01-01', 'periodicity' => 'once',
            'costable_type' => $managementGroup->getMorphClass(), 'costable_id' => $managementGroup->id,
        ]);
        $openInvoice->costs()->create([
            'costable_type' => $managementGroup->getMorphClass(), 'costable_id' => $managementGroup->id,
            'billing_interval_id' => $billingInterval->id, 'cost_category_id' => $category->id,
            'amount' => 50, 'period_date' => '2026-01-01',
        ]);

        $withoutAnyCosts = Invoice::create([
            'invoice_issuer_id' => $issuer->id, 'amount' => 20, 'description' => 'Ganz ohne Buchungen',
            'invoice_date' => '2026-01-01', 'periodicity' => 'once',
            'costable_type' => $managementGroup->getMorphClass(), 'costable_id' => $managementGroup->id,
        ]);

        $response = $this->actingAs($user)->get(route('invoices.index'));

        $response->assertOk();
        $ids = $response->viewData('invoices')->pluck('id')->all();
        $this->assertNotContains($completedInvoice->id, $ids, 'a fully booked invoice should be hidden by default');
        $this->assertContains($openInvoice->id, $ids);
        $this->assertContains($withoutAnyCosts->id, $ids, 'an invoice without any cost rows is not "erledigt" and must stay visible');
    }

    public function test_the_index_shows_completed_invoices_when_toggled_on(): void
    {
        $this->seed(CostCategorySeeder::class);
        $user = User::factory()->create();
        $group = $user->groups()->first();
        $managementGroup = $this->makeManagementGroup($user);
        $issuer = InvoiceIssuer::create(['user_group_id' => $group->id, 'name' => 'Stadtwerke', 'street' => 'A', 'postal_code' => '1', 'city' => 'B']);
        $category = CostCategory::first();
        $billingInterval = $managementGroup->billingIntervals()->first();

        $completedInvoice = Invoice::create([
            'invoice_issuer_id' => $issuer->id, 'amount' => 100, 'description' => 'Erledigte Rechnung',
            'invoice_date' => '2026-01-01', 'periodicity' => 'once',
            'costable_type' => $managementGroup->getMorphClass(), 'costable_id' => $managementGroup->id,
        ]);
        $completedInvoice->costs()->create([
            'costable_type' => $managementGroup->getMorphClass(), 'costable_id' => $managementGroup->id,
            'billing_interval_id' => $billingInterval->id, 'cost_category_id' => $category->id,
            'amount' => 100, 'period_date' => '2026-01-01', 'booking_date' => '2026-01-05',
        ]);

        $response = $this->actingAs($user)->get(route('invoices.index', ['show_completed' => 1]));

        $response->assertOk();
        $ids = $response->viewData('invoices')->pluck('id')->all();
        $this->assertContains($completedInvoice->id, $ids);
        $response->assertSee(__('Erledigte Rechnungen ausblenden'));
    }

    /**
     * Konzept.md "#### Rechnungen findem": Suchdialog mit mehreren Kriterien. Baut
     * drei klar unterscheidbare Rechnungen und prüft jedes Suchkriterium einzeln.
     *
     * @return array{0: User, 1: Invoice, 2: Invoice, 3: Invoice, 4: InvoiceIssuer, 5: InvoiceIssuer}
     */
    private function makeSearchFixture(): array
    {
        $this->seed(CostCategorySeeder::class);
        $user = User::factory()->create();
        $group = $user->groups()->first();
        $managementGroup = $this->makeManagementGroup($user);
        $billingInterval = $managementGroup->billingIntervals()->first();

        $stadtwerke = InvoiceIssuer::create(['user_group_id' => $group->id, 'name' => 'Stadtwerke', 'street' => 'A', 'postal_code' => '1', 'city' => 'B']);
        $versicherung = InvoiceIssuer::create(['user_group_id' => $group->id, 'name' => 'Versicherung', 'street' => 'A', 'postal_code' => '1', 'city' => 'B']);
        $heizkosten = CostCategory::where('name', 'Heizkosten')->first();
        $zinsen = CostCategory::create(['name' => 'Zinsen', 'is_relevant_for_billing' => false]);

        $invoiceA = Invoice::create([
            'invoice_issuer_id' => $stadtwerke->id, 'amount' => 100, 'description' => 'Stromrechnung Haus',
            'invoice_date' => '2026-01-10', 'periodicity' => 'once', 'invoice_number' => 'RE-2026-001',
            'costable_type' => $managementGroup->getMorphClass(), 'costable_id' => $managementGroup->id,
            'file_path' => 'invoices/a.pdf', 'file_original_name' => 'a.pdf',
        ]);
        $invoiceA->costs()->create([
            'costable_type' => $managementGroup->getMorphClass(), 'costable_id' => $managementGroup->id,
            'billing_interval_id' => $billingInterval->id, 'cost_category_id' => $heizkosten->id,
            'amount' => 100, 'period_date' => '2026-01-10', 'booking_date' => '2026-01-15',
        ]);

        $invoiceB = Invoice::create([
            'invoice_issuer_id' => $versicherung->id, 'amount' => 500, 'description' => 'Jahresprämie',
            'invoice_date' => '2026-06-01', 'periodicity' => 'once',
            'costable_type' => $managementGroup->getMorphClass(), 'costable_id' => $managementGroup->id,
        ]);
        $invoiceB->costs()->create([
            'costable_type' => $managementGroup->getMorphClass(), 'costable_id' => $managementGroup->id,
            'billing_interval_id' => $billingInterval->id, 'cost_category_id' => $zinsen->id,
            'amount' => 500, 'period_date' => '2026-06-01',
        ]);

        $invoiceC = Invoice::create([
            'invoice_issuer_id' => $stadtwerke->id, 'amount' => 20, 'description' => 'Mahngebühr',
            'invoice_date' => '2026-11-20', 'periodicity' => 'once',
            'costable_type' => $managementGroup->getMorphClass(), 'costable_id' => $managementGroup->id,
        ]);

        return [$user, $invoiceA, $invoiceB, $invoiceC, $stadtwerke, $versicherung];
    }

    public function test_search_filters_by_issuer(): void
    {
        [$user, $invoiceA, $invoiceB, $invoiceC, $stadtwerke] = $this->makeSearchFixture();

        $response = $this->actingAs($user)->get(route('invoices.index', ['show_completed' => 1, 'issuer_id' => $stadtwerke->id]));

        $ids = $response->viewData('invoices')->pluck('id')->all();
        $this->assertEqualsCanonicalizing([$invoiceA->id, $invoiceC->id], $ids);
        $this->assertNotContains($invoiceB->id, $ids);
    }

    public function test_search_filters_by_invoice_date_range(): void
    {
        [$user, $invoiceA, $invoiceB, $invoiceC] = $this->makeSearchFixture();

        $response = $this->actingAs($user)->get(route('invoices.index', [
            'show_completed' => 1, 'date_from' => '2026-05-01', 'date_to' => '2026-07-01',
        ]));

        $ids = $response->viewData('invoices')->pluck('id')->all();
        $this->assertSame([$invoiceB->id], $ids);
        $this->assertNotContains($invoiceA->id, $ids);
        $this->assertNotContains($invoiceC->id, $ids);
    }

    public function test_search_filters_by_amount_range(): void
    {
        [$user, $invoiceA, $invoiceB, $invoiceC] = $this->makeSearchFixture();

        $response = $this->actingAs($user)->get(route('invoices.index', [
            'show_completed' => 1, 'amount_min' => 50, 'amount_max' => 200,
        ]));

        $ids = $response->viewData('invoices')->pluck('id')->all();
        $this->assertSame([$invoiceA->id], $ids);
    }

    public function test_search_filters_by_cost_category(): void
    {
        [$user, $invoiceA, $invoiceB, $invoiceC] = $this->makeSearchFixture();
        $heizkosten = CostCategory::where('name', 'Heizkosten')->first();

        $response = $this->actingAs($user)->get(route('invoices.index', [
            'show_completed' => 1, 'cost_category_id' => $heizkosten->id,
        ]));

        $ids = $response->viewData('invoices')->pluck('id')->all();
        $this->assertSame([$invoiceA->id], $ids);
    }

    public function test_search_filters_by_description(): void
    {
        [$user, $invoiceA, $invoiceB, $invoiceC] = $this->makeSearchFixture();

        $response = $this->actingAs($user)->get(route('invoices.index', [
            'show_completed' => 1, 'description' => 'prämie',
        ]));

        $ids = $response->viewData('invoices')->pluck('id')->all();
        $this->assertSame([$invoiceB->id], $ids);
    }

    public function test_search_filters_by_invoice_number(): void
    {
        [$user, $invoiceA, $invoiceB, $invoiceC] = $this->makeSearchFixture();

        $response = $this->actingAs($user)->get(route('invoices.index', [
            'show_completed' => 1, 'invoice_number' => '2026-001',
        ]));

        $ids = $response->viewData('invoices')->pluck('id')->all();
        $this->assertSame([$invoiceA->id], $ids);
    }

    public function test_search_filters_by_booking_date_range(): void
    {
        [$user, $invoiceA, $invoiceB, $invoiceC] = $this->makeSearchFixture();

        $response = $this->actingAs($user)->get(route('invoices.index', [
            'show_completed' => 1, 'booking_date_from' => '2026-01-01', 'booking_date_to' => '2026-01-31',
        ]));

        $ids = $response->viewData('invoices')->pluck('id')->all();
        // Nur invoiceA hat eine gebuchte Kostenzeile (15.01.2026); invoiceB/C sind
        // entweder ganz offen oder haben gar keine Buchung.
        $this->assertSame([$invoiceA->id], $ids);
    }

    public function test_search_filters_by_file_presence(): void
    {
        [$user, $invoiceA, $invoiceB, $invoiceC] = $this->makeSearchFixture();

        $withFile = $this->actingAs($user)->get(route('invoices.index', ['show_completed' => 1, 'has_file' => 'yes']));
        $this->assertSame([$invoiceA->id], $withFile->viewData('invoices')->pluck('id')->all());

        $withoutFile = $this->actingAs($user)->get(route('invoices.index', ['show_completed' => 1, 'has_file' => 'no']));
        $this->assertEqualsCanonicalizing([$invoiceB->id, $invoiceC->id], $withoutFile->viewData('invoices')->pluck('id')->all());
    }

    /**
     * Standard-Verknüpfung ("match" fehlt bzw. "all") ist UND: kein Treffer, wenn
     * eine Rechnung nicht ALLE Kriterien gleichzeitig erfüllt.
     */
    public function test_search_defaults_to_matching_all_criteria(): void
    {
        [$user, $invoiceA, $invoiceB, $invoiceC, $stadtwerke] = $this->makeSearchFixture();

        $response = $this->actingAs($user)->get(route('invoices.index', [
            'show_completed' => 1, 'issuer_id' => $stadtwerke->id, 'amount_min' => 500,
        ]));

        $this->assertSame([], $response->viewData('invoices')->pluck('id')->all());
    }

    /**
     * Per AskUserQuestion geklärt: ein globaler UND/ODER-Schalter statt eines vollen
     * Ausdrucksbaums – "match=any" verknüpft alle ausgefüllten Kriterien mit ODER.
     */
    public function test_search_any_match_combines_criteria_with_or(): void
    {
        [$user, $invoiceA, $invoiceB, $invoiceC, $stadtwerke] = $this->makeSearchFixture();

        $response = $this->actingAs($user)->get(route('invoices.index', [
            'show_completed' => 1, 'issuer_id' => $stadtwerke->id, 'amount_min' => 500, 'match' => 'any',
        ]));

        // issuer_id=Stadtwerke matcht A+C, amount_min=500 matcht B -> ODER-Vereinigung aller drei.
        $ids = $response->viewData('invoices')->pluck('id')->all();
        $this->assertEqualsCanonicalizing([$invoiceA->id, $invoiceB->id, $invoiceC->id], $ids);
    }

    public function test_an_invoice_can_be_deleted(): void
    {
        $user = User::factory()->create();
        $group = $user->groups()->first();
        $managementGroup = $this->makeManagementGroup($user);
        $issuer = InvoiceIssuer::create(['user_group_id' => $group->id, 'name' => 'Stadtwerke', 'street' => 'A', 'postal_code' => '1', 'city' => 'B']);
        $invoice = Invoice::create([
            'invoice_issuer_id' => $issuer->id, 'amount' => 100,
            'invoice_date' => '2026-01-01', 'periodicity' => 'once',
            'costable_type' => $managementGroup->getMorphClass(), 'costable_id' => $managementGroup->id,
        ]);

        $response = $this->actingAs($user)->delete(route('invoices.destroy', $invoice));

        $response->assertRedirect(route('invoices.index'));
        $this->assertNull(Invoice::find($invoice->id));
    }
}
