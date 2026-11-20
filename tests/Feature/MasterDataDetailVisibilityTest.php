<?php

// KI-generiert

namespace Tests\Feature;

use App\Models\Group;
use App\Models\Invoice;
use App\Models\InvoiceIssuer;
use App\Models\Lease;
use App\Models\ManagementGroup;
use App\Models\Tenant;
use App\Models\User;
use App\Services\GroupOwnership\GroupOwnership;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Api\Concerns\CreatesApiFixtures;
use Tests\TestCase;

/**
 * Die Detailseiten eines Mieters bzw. Rechnungsstellers zeigen nur Mietverträge und
 * Rechnungen, die der Betrachter sehen darf – auch wenn Mieter oder Rechnungssteller
 * (Altdaten) noch in Verwaltungsgruppen anderer Gruppen verwendet werden.
 */
class MasterDataDetailVisibilityTest extends TestCase
{
    use CreatesApiFixtures, RefreshDatabase;

    private User $member;

    private User $owner;

    private Group $shared;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = User::factory()->create();
        $this->member = User::factory()->create();
        $this->shared = Group::create(['name' => 'Gemeinsam', 'owner_id' => $this->owner->id]);
        $this->shared->users()->attach([$this->owner->id => ['role' => 'admin'], $this->member->id => ['role' => 'user']]);
    }

    private function leaseIn(ManagementGroup $managementGroup, Tenant $tenant, string $notes): Lease
    {
        $rentalUnit = $this->makeRentalUnit($this->owner, managementUnit: $this->makeManagementUnit($this->owner, $managementGroup));
        $lease = Lease::create(['rental_unit_id' => $rentalUnit->id, 'start_date' => '2026-01-01', 'rent_adjustment_type' => 'fixed', 'cold_rent' => 500, 'notes' => $notes]);
        GroupOwnership::suspended(fn () => $lease->tenants()->attach($tenant->id));

        return $lease;
    }

    private function invoiceFor(InvoiceIssuer $issuer, ?ManagementGroup $managementGroup, float $amount): Invoice
    {
        return GroupOwnership::suspended(fn () => Invoice::create([
            'invoice_issuer_id' => $issuer->id, 'amount' => $amount, 'invoice_date' => '2026-01-01', 'periodicity' => 'once',
            'costable_type' => $managementGroup?->getMorphClass(), 'costable_id' => $managementGroup?->id,
        ]));
    }

    public function test_a_tenant_shows_only_leases_the_viewer_may_see(): void
    {
        $sharedGroup = ManagementGroup::create(['description' => 'Geteilt', 'user_group_id' => $this->shared->id]);
        $privateGroup = $this->makeManagementGroup($this->owner);
        $tenant = Tenant::create(['user_group_id' => $this->shared->id, 'first_name' => 'Gerd', 'last_name' => 'Gemeinsam', 'street' => 'A', 'postal_code' => '1', 'city' => 'B']);
        $visible = $this->leaseIn($sharedGroup, $tenant, 'sichtbar');
        $hidden = $this->leaseIn($privateGroup, $tenant, 'versteckt');

        $this->actingAs($this->member)->get(route('tenants.show', $tenant))
            ->assertOk()->assertSee(route('leases.show', $visible))->assertDontSee(route('leases.show', $hidden));

        Sanctum::actingAs($this->member);
        $this->getJson(route('api.tenants.show', $tenant))->assertOk()
            ->assertJsonCount(1, 'leases')->assertJsonPath('leases.0.id', $visible->id);

        Sanctum::actingAs($this->owner);
        $this->getJson(route('api.tenants.show', $tenant))->assertOk()->assertJsonCount(2, 'leases');
    }

    public function test_an_issuer_shows_only_invoices_the_viewer_may_see_plus_those_without_assignment(): void
    {
        $sharedGroup = ManagementGroup::create(['description' => 'Geteilt', 'user_group_id' => $this->shared->id]);
        $privateGroup = $this->makeManagementGroup($this->owner);
        $issuer = InvoiceIssuer::create(['user_group_id' => $this->shared->id, 'name' => 'Stadtwerke', 'street' => 'A', 'postal_code' => '1', 'city' => 'B']);
        $visible = $this->invoiceFor($issuer, $sharedGroup, 11.11);
        $unassigned = $this->invoiceFor($issuer, null, 22.22);
        $hidden = $this->invoiceFor($issuer, $privateGroup, 33.33);

        $this->actingAs($this->member)->get(route('invoice-issuers.show', $issuer))->assertOk()
            ->assertSee(route('invoices.show', $visible))
            ->assertSee(route('invoices.show', $unassigned))
            ->assertDontSee(route('invoices.show', $hidden))
            ->assertDontSee('33,33');

        $this->actingAs($this->owner)->get(route('invoice-issuers.show', $issuer))->assertOk()
            ->assertSee(route('invoices.show', $hidden));
    }
}
