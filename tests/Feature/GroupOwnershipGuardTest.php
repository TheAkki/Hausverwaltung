<?php

// KI-generiert

namespace Tests\Feature;

use App\Exceptions\GroupMismatchException;
use App\Models\Cost;
use App\Models\CostCategory;
use App\Models\Group;
use App\Models\Invoice;
use App\Models\InvoiceIssuer;
use App\Models\Lease;
use App\Models\ManagementGroup;
use App\Models\RentalUnit;
use App\Models\Tenant;
use App\Models\User;
use App\Services\GroupOwnership\GroupOwnership;
use Database\Seeders\CostCategorySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Api\Concerns\CreatesApiFixtures;
use Tests\TestCase;

/**
 * Weboberfläche und API lehnen Stammdaten aus einer anderen Benutzergruppe als der
 * der Verwaltungsgruppe ab – auch aus einer eigenen (z. B. der persönlichen Gruppe für
 * eine gemeinsame Verwaltungsgruppe). Bestehende Zuordnungen (Altdaten) bleiben
 * bearbeitbar.
 */
class GroupOwnershipGuardTest extends TestCase
{
    use CreatesApiFixtures, RefreshDatabase;

    private User $user;

    private Group $shared;

    private ManagementGroup $managementGroup;

    private RentalUnit $rentalUnit;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CostCategorySeeder::class);
        $this->user = User::factory()->create();
        $this->shared = Group::create(['name' => 'Gemeinsam', 'owner_id' => $this->user->id]);
        $this->shared->users()->attach($this->user->id, ['role' => 'admin']);
        $this->managementGroup = ManagementGroup::create(['description' => 'Geteilt', 'user_group_id' => $this->shared->id]);
        $this->rentalUnit = $this->makeRentalUnit($this->user, managementUnit: $this->makeManagementUnit($this->user, $this->managementGroup));
    }

    private function personalTenant(): Tenant
    {
        return $this->makeTenant($this->user, 'Privat');
    }

    private function sharedTenant(): Tenant
    {
        return Tenant::create(['user_group_id' => $this->shared->id, 'first_name' => 'Gerd', 'last_name' => 'Gemeinsam', 'street' => 'A', 'postal_code' => '1', 'city' => 'B']);
    }

    private function sharedIssuer(): InvoiceIssuer
    {
        return InvoiceIssuer::create(['user_group_id' => $this->shared->id, 'name' => 'Gemeinsam', 'street' => 'A', 'postal_code' => '1', 'city' => 'B']);
    }

    private function personalCategory(): CostCategory
    {
        return CostCategory::create(['user_group_id' => $this->groupOf($this->user)->id, 'name' => 'Privat', 'is_relevant_for_billing' => true]);
    }

    /**
     * @return array<string, mixed>
     */
    private function leasePayload(array $tenantIds): array
    {
        return [
            'rental_unit_id' => $this->rentalUnit->id, 'start_date' => '2026-01-01',
            'rent_adjustment_type' => 'fixed', 'cold_rent' => 500, 'tenant_ids' => $tenantIds,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function invoicePayload(InvoiceIssuer $issuer, ?CostCategory $category = null): array
    {
        return [
            'invoice_issuer_id' => $issuer->id, 'invoice_date' => '2026-01-15', 'periodicity' => 'once', 'amount' => 120,
            'costable' => "management_group:{$this->managementGroup->id}",
            'billing_interval_id' => $this->managementGroup->billingIntervals()->first()->id,
            'cost_category_id' => ($category ?? CostCategory::whereNull('user_group_id')->first())->id,
        ];
    }

    private function sharedInvoice(): Invoice
    {
        return Invoice::create([
            'invoice_issuer_id' => $this->sharedIssuer()->id, 'amount' => 100, 'invoice_date' => '2026-01-01', 'periodicity' => 'once',
            'costable_type' => $this->managementGroup->getMorphClass(), 'costable_id' => $this->managementGroup->id,
        ]);
    }

    private function legacyLease(Tenant $tenant): Lease
    {
        $lease = Lease::create(['rental_unit_id' => $this->rentalUnit->id, 'start_date' => '2026-01-01', 'rent_adjustment_type' => 'fixed', 'cold_rent' => 500]);
        GroupOwnership::suspended(fn () => $lease->tenants()->attach($tenant->id));

        return $lease;
    }

    public function test_the_web_lease_form_rejects_a_tenant_of_another_group_without_creating_the_lease(): void
    {
        $this->actingAs($this->user)
            ->post(route('leases.store'), $this->leasePayload([$this->personalTenant()->id]))
            ->assertSessionHasErrors(GroupOwnership::FIELD_TENANTS);

        $this->assertSame(0, Lease::count());

        $this->actingAs($this->user)
            ->post(route('leases.store'), $this->leasePayload([$this->sharedTenant()->id]))
            ->assertSessionHasNoErrors();
        $this->assertSame(1, Lease::count());
    }

    public function test_the_api_rejects_a_tenant_of_another_group(): void
    {
        Sanctum::actingAs($this->user);

        $this->postJson(route('api.leases.store'), $this->leasePayload([$this->personalTenant()->id]))
            ->assertUnprocessable()->assertJsonValidationErrors(GroupOwnership::FIELD_TENANTS);

        $this->assertSame(0, Lease::count());
    }

    public function test_a_legacy_lease_stays_editable_but_takes_no_further_foreign_tenant(): void
    {
        $legacy = $this->personalTenant();
        $lease = $this->legacyLease($legacy);
        $payload = fn (array $ids) => ['tenant_ids' => $ids, 'start_date' => '2026-01-01', 'rent_adjustment_type' => 'fixed', 'cold_rent' => 650];

        $this->actingAs($this->user)->put(route('leases.update', $lease), $payload([$legacy->id]))->assertSessionHasNoErrors();
        $this->assertSame(650.0, (float) $lease->fresh()->cold_rent);

        $other = $this->makeTenant($this->user, 'Noch einer');
        $this->actingAs($this->user)->put(route('leases.update', $lease), $payload([$legacy->id, $other->id]))
            ->assertSessionHasErrors(GroupOwnership::FIELD_TENANTS);

        Sanctum::actingAs($this->user);
        $this->putJson(route('api.leases.update', $lease), $payload([$legacy->id, $other->id]))
            ->assertUnprocessable()->assertJsonValidationErrors(GroupOwnership::FIELD_TENANTS);
        $this->assertSame([$legacy->id], $lease->tenants()->pluck('tenants.id')->all());
    }

    public function test_the_lease_forms_offer_only_tenants_of_the_management_groups_group(): void
    {
        $personal = $this->personalTenant();
        $shared = $this->sharedTenant();

        $this->actingAs($this->user)->get(route('leases.create', ['rental_unit_id' => $this->rentalUnit->id]))
            ->assertOk()->assertSee('Gemeinsam')->assertDontSee('Privat');
        $this->actingAs($this->user)->get(route('leases.create', ['management_unit_id' => $this->rentalUnit->management_unit_id]))
            ->assertOk()->assertSee('Gemeinsam')->assertDontSee('Privat');
        $this->actingAs($this->user)->get(route('leases.create'))
            ->assertOk()->assertSee('Gemeinsam')->assertSee('Privat');

        $lease = $this->legacyLease($personal);
        $lease->tenants()->attach($shared->id);
        $this->actingAs($this->user)->get(route('leases.edit', $lease))
            ->assertOk()->assertSee('Gemeinsam')->assertSee('Privat');
    }

    public function test_the_web_invoice_form_rejects_a_foreign_issuer_or_category_before_saving(): void
    {
        $this->actingAs($this->user)->post(route('invoices.store'), $this->invoicePayload($this->makeIssuer($this->user)))
            ->assertSessionHasErrors(GroupOwnership::FIELD_ISSUER);
        $this->actingAs($this->user)->post(route('invoices.store'), $this->invoicePayload($this->sharedIssuer(), $this->personalCategory()))
            ->assertSessionHasErrors(GroupOwnership::FIELD_CATEGORY);

        $this->assertSame(0, Invoice::count());
    }

    public function test_changing_an_invoice_to_a_foreign_issuer_is_rejected(): void
    {
        $invoice = $this->sharedInvoice();

        $this->actingAs($this->user)->put(route('invoices.update', $invoice), [
            'invoice_issuer_id' => $this->makeIssuer($this->user)->id, 'amount' => 100, 'invoice_date' => '2026-01-01',
        ])->assertSessionHasErrors(GroupOwnership::FIELD_ISSUER);

        Sanctum::actingAs($this->user);
        $this->putJson(route('api.invoices.update', $invoice), [
            'invoice_issuer_id' => $this->makeIssuer($this->user)->id, 'amount' => 100, 'invoice_date' => '2026-01-01',
        ])->assertUnprocessable()->assertJsonValidationErrors(GroupOwnership::FIELD_ISSUER);
    }

    public function test_the_api_rejects_a_foreign_issuer_or_category_for_a_simple_invoice(): void
    {
        Sanctum::actingAs($this->user);

        $this->postJson(route('api.invoices.store'), $this->invoicePayload($this->makeIssuer($this->user)))
            ->assertUnprocessable()->assertJsonValidationErrors(GroupOwnership::FIELD_ISSUER);
        $this->postJson(route('api.invoices.store'), $this->invoicePayload($this->sharedIssuer(), $this->personalCategory()))
            ->assertUnprocessable()->assertJsonValidationErrors(GroupOwnership::FIELD_CATEGORY);
        $this->assertSame(0, Invoice::count());
    }

    public function test_completing_a_series_with_a_foreign_category_is_rejected(): void
    {
        $invoice = $this->sharedInvoice();
        $invoice->update(['periodicity' => 'monthly', 'recurrence_start' => '2026-01-01', 'recurrence_end' => '2026-03-31']);

        $this->actingAs($this->user)->post(route('invoices.complete-series', $invoice), ['cost_category_id' => $this->personalCategory()->id])
            ->assertSessionHasErrors(GroupOwnership::FIELD_CATEGORY);
        $this->assertSame(0, $invoice->costs()->count());
    }

    public function test_a_cost_row_with_a_foreign_category_is_rejected(): void
    {
        $invoice = $this->sharedInvoice();

        $this->actingAs($this->user)->post(route('costs.store'), [
            'invoice_id' => $invoice->id, 'costable_type' => 'management_group', 'costable_id' => $this->managementGroup->id,
            'billing_interval_id' => $this->managementGroup->billingIntervals()->first()->id,
            'cost_category_id' => $this->personalCategory()->id, 'amount' => 10,
        ])->assertSessionHasErrors(GroupOwnership::FIELD_CATEGORY);

        $this->assertSame(0, Cost::count());
    }

    public function test_a_cost_row_on_a_management_group_of_another_group_than_the_issuer_is_rejected(): void
    {
        $invoice = $this->sharedInvoice();
        $personalGroup = $this->makeManagementGroup($this->user);

        try {
            $invoice->costs()->create([
                'costable_type' => $personalGroup->getMorphClass(), 'costable_id' => $personalGroup->id,
                'billing_interval_id' => $personalGroup->billingIntervals()->first()->id,
                'cost_category_id' => CostCategory::whereNull('user_group_id')->first()->id, 'amount' => 10,
            ]);
            $this->fail('Die Kostenzeile hätte abgelehnt werden müssen.');
        } catch (GroupMismatchException $e) {
            $this->assertSame('costable_id', $e->field);
        }
    }

    public function test_an_allocation_key_for_a_foreign_category_is_rejected(): void
    {
        $category = $this->personalCategory();

        $this->actingAs($this->user)
            ->patch(route('management-groups.cost-categories.update', [$this->managementGroup, $category]), ['allocation_key' => 'area'])
            ->assertSessionHasErrors(GroupOwnership::FIELD_CATEGORY);

        Sanctum::actingAs($this->user);
        $this->putJson(route('api.management-groups.cost-categories.update', [$this->managementGroup, $category]), ['allocation_key' => 'area'])
            ->assertUnprocessable()->assertJsonValidationErrors(GroupOwnership::FIELD_CATEGORY);
        $this->assertSame(0, $this->managementGroup->costCategories()->count());
    }

    public function test_a_used_tenant_or_issuer_cannot_change_its_group_but_an_unused_one_can(): void
    {
        $tenant = $this->sharedTenant();
        $this->legacyLease($tenant);
        $this->sharedInvoice();
        $issuer = InvoiceIssuer::sole();
        $personal = $this->groupOf($this->user)->id;
        $tenantFields = ['user_group_id' => $personal, 'last_name' => 'Gemeinsam', 'street' => 'A', 'postal_code' => '1', 'city' => 'B'];

        $this->actingAs($this->user)->put(route('tenants.update', $tenant), $tenantFields)->assertSessionHasErrors(GroupOwnership::FIELD_GROUP);
        $this->actingAs($this->user)->put(route('invoice-issuers.update', $issuer), ['user_group_id' => $personal, 'name' => 'Gemeinsam', 'street' => 'A', 'postal_code' => '1', 'city' => 'B'])
            ->assertSessionHasErrors(GroupOwnership::FIELD_GROUP);
        $this->assertSame($this->shared->id, $tenant->fresh()->user_group_id);
        $this->assertSame($this->shared->id, $issuer->fresh()->user_group_id);

        $unused = $this->sharedTenant();
        $this->actingAs($this->user)->put(route('tenants.update', $unused), $tenantFields)->assertSessionHasNoErrors();
        $this->assertSame($personal, $unused->fresh()->user_group_id);
    }

    public function test_the_checks_are_skipped_while_suspended(): void
    {
        $lease = $this->legacyLease($this->personalTenant());
        $tenant = $this->sharedTenant();
        $lease->tenants()->attach($tenant->id);
        $this->sharedInvoice();
        $personal = $this->groupOf($this->user)->id;

        GroupOwnership::suspended(function () use ($tenant, $personal) {
            $tenant->update(['user_group_id' => $personal]);
            InvoiceIssuer::sole()->update(['user_group_id' => $personal]);
            $this->managementGroup->update(['user_group_id' => $personal]);
        });

        $this->assertSame('Privat', $lease->tenants()->orderBy('id')->first()->last_name);
        $this->assertSame([$personal, $personal, $personal], [$tenant->fresh()->user_group_id, InvoiceIssuer::sole()->user_group_id, $this->managementGroup->fresh()->user_group_id]);
    }

    public function test_a_cost_row_without_a_resolvable_management_group_is_not_checked(): void
    {
        $invoice = $this->sharedInvoice();

        $cost = $invoice->costs()->create([
            'costable_type' => $this->managementGroup->getMorphClass(), 'costable_id' => 999999,
            'billing_interval_id' => $this->managementGroup->billingIntervals()->first()->id,
            'cost_category_id' => $this->personalCategory()->id, 'amount' => 10,
        ]);

        $this->assertTrue($cost->exists);
    }
}
