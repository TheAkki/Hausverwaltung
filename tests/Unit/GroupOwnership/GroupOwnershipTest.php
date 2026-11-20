<?php

// KI-generiert

namespace Tests\Unit\GroupOwnership;

use App\Exceptions\GroupMismatchException;
use App\Models\CostCategory;
use App\Models\Group;
use App\Models\Invoice;
use App\Models\InvoiceIssuer;
use App\Models\Tenant;
use App\Models\User;
use App\Services\GroupOwnership\GroupOwnership;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Api\Concerns\CreatesApiFixtures;
use Tests\TestCase;

class GroupOwnershipTest extends TestCase
{
    use CreatesApiFixtures, RefreshDatabase;

    private User $user;

    private Group $shared;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
        $this->shared = Group::create(['name' => 'Gemeinsam', 'owner_id' => $this->user->id]);
    }

    private function tenantIn(?int $groupId): Tenant
    {
        return Tenant::create(['user_group_id' => $groupId, 'first_name' => 'Anna', 'last_name' => 'Muster', 'street' => 'A', 'postal_code' => '1', 'city' => 'B']);
    }

    private function issuerIn(?int $groupId, string $name = 'Bank'): InvoiceIssuer
    {
        return InvoiceIssuer::create(['user_group_id' => $groupId, 'name' => $name, 'street' => 'A', 'postal_code' => '1', 'city' => 'B']);
    }

    private function categoryIn(?int $groupId, string $name = 'Eigene'): CostCategory
    {
        return CostCategory::create(['user_group_id' => $groupId, 'name' => $name, 'is_relevant_for_billing' => true]);
    }

    public function test_master_data_fits_only_its_own_group_and_system_or_groupless_entries_fit_everywhere(): void
    {
        $managementGroup = $this->makeManagementGroup($this->user);
        $own = $managementGroup->user_group_id;

        $this->assertTrue(GroupOwnership::tenantFits($this->tenantIn($own), $managementGroup));
        $this->assertTrue(GroupOwnership::tenantFits($this->tenantIn(null), $managementGroup));
        $this->assertFalse(GroupOwnership::tenantFits($this->tenantIn($this->shared->id), $managementGroup));
        $this->assertTrue(GroupOwnership::issuerFits($this->issuerIn($own), $managementGroup));
        $this->assertTrue(GroupOwnership::issuerFits($this->issuerIn(null), $managementGroup));
        $this->assertFalse(GroupOwnership::issuerFits($this->issuerIn($this->shared->id), $managementGroup));
        $this->assertTrue(GroupOwnership::categoryFits($this->categoryIn(null), $managementGroup));
        $this->assertTrue(GroupOwnership::categoryFits($this->categoryIn($own), $managementGroup));
        $this->assertFalse(GroupOwnership::categoryFits($this->categoryIn($this->shared->id), $managementGroup));
    }

    public function test_a_mismatch_names_the_entry_both_groups_and_the_field(): void
    {
        $managementGroup = $this->makeManagementGroup($this->user);

        try {
            GroupOwnership::assertIssuerFits($this->issuerIn($this->shared->id, 'Stadtwerke'), $managementGroup, 'feld');
            $this->fail('Die Prüfung hätte scheitern müssen.');
        } catch (GroupMismatchException $e) {
            $this->assertSame('feld', $e->field);
            $this->assertStringContainsString('Rechnungssteller „Stadtwerke“ gehört zur Gruppe „Gemeinsam“', $e->getMessage());
            $this->assertStringContainsString('die Verwaltungsgruppe „MG“ aber zur Gruppe „'.$this->groupOf($this->user)->name.'“', $e->getMessage());
        }
    }

    public function test_tenant_and_category_assertions_use_their_default_fields(): void
    {
        $managementGroup = $this->makeManagementGroup($this->user);

        foreach ([
            GroupOwnership::FIELD_TENANTS => fn () => GroupOwnership::assertTenantFits($this->tenantIn($this->shared->id), $managementGroup),
            GroupOwnership::FIELD_CATEGORY => fn () => GroupOwnership::assertCategoryFits($this->categoryIn($this->shared->id), $managementGroup),
        ] as $field => $check) {
            try {
                $check();
                $this->fail("{$field} hätte scheitern müssen.");
            } catch (GroupMismatchException $e) {
                $this->assertSame($field, $e->field);
            }
        }
    }

    public function test_tenant_ids_skip_tenants_that_are_already_attached(): void
    {
        $managementGroup = $this->makeManagementGroup($this->user);
        $foreign = $this->tenantIn($this->shared->id);

        GroupOwnership::assertTenantIdsFit([$foreign->id, (string) $this->tenantIn($managementGroup->user_group_id)->id], $managementGroup, [(string) $foreign->id]);

        $this->expectException(GroupMismatchException::class);
        GroupOwnership::assertTenantIdsFit([$foreign->id], $managementGroup);
    }

    public function test_violations_list_every_foreign_entry_the_management_group_uses(): void
    {
        $managementGroup = $this->makeManagementGroup($this->user);
        $lease = $this->makeLease($this->user, $this->makeRentalUnit($this->user, managementUnit: $this->makeManagementUnit($this->user, $managementGroup)));
        $category = $this->categoryIn($managementGroup->user_group_id, 'Lohn');
        $managementGroup->costCategories()->attach($category->id, ['allocation_key' => 'area']);
        Invoice::create([
            'invoice_issuer_id' => $this->issuerIn($managementGroup->user_group_id, 'Stadtwerke')->id, 'amount' => 10, 'invoice_date' => '2026-01-01',
            'periodicity' => 'once', 'costable_type' => $lease->getMorphClass(), 'costable_id' => $lease->id,
        ]);

        $this->assertSame([], GroupOwnership::violations($managementGroup, $managementGroup->user_group_id));
        $this->assertSame(
            ['Mieter „Max Mustermann“', 'Rechnungssteller „Stadtwerke“', 'Kategorie „Lohn“'],
            GroupOwnership::violations($managementGroup, $this->shared->id)
        );

        $this->expectException(GroupMismatchException::class);
        $this->expectExceptionMessage('Die Verwaltungsgruppe passt nicht zur Gruppe „Gemeinsam“');
        GroupOwnership::assertNoViolations($managementGroup, $this->shared->id);
    }

    public function test_suspension_is_nested_and_ends_even_after_an_exception(): void
    {
        $this->assertFalse(GroupOwnership::isSuspended());

        $inner = GroupOwnership::suspended(fn () => GroupOwnership::suspended(fn () => GroupOwnership::isSuspended()));
        $this->assertTrue($inner);

        try {
            GroupOwnership::suspended(fn () => throw new \LogicException('x'));
        } catch (\LogicException) {
        }

        $this->assertFalse(GroupOwnership::isSuspended());
    }

    public function test_the_management_group_of_a_costable_is_null_without_one(): void
    {
        $managementGroup = $this->makeManagementGroup($this->user);

        $this->assertNull(GroupOwnership::managementGroupOf(null, null));
        $this->assertNull(GroupOwnership::managementGroupOf('unbekannt', 1));
        $this->assertNull(GroupOwnership::managementGroupOf($managementGroup->getMorphClass(), 999999));
        $this->assertTrue(GroupOwnership::managementGroupOf($managementGroup->getMorphClass(), $managementGroup->id)->is($managementGroup));
    }
}
