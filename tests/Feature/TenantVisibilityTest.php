<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Regression coverage for a cross-account data leak: tenants (Mieter) belonging to
 * one user's Benutzergruppe must not be visible or editable by a user from a
 * different Benutzergruppe.
 */
class TenantVisibilityTest extends TestCase
{
    use RefreshDatabase;

    private function makeTenantFor(User $user, string $lastName): Tenant
    {
        $group = $user->groups()->first();

        return Tenant::create([
            'user_group_id' => $group->id,
            'last_name' => $lastName,
            'street' => 'A', 'postal_code' => '1', 'city' => 'B',
        ]);
    }

    public function test_the_tenant_index_only_lists_tenants_of_the_current_users_own_groups(): void
    {
        $owner = User::factory()->create();
        $this->makeTenantFor($owner, 'Vermieterkunde');

        $otherUser = User::factory()->create();

        $response = $this->actingAs($otherUser)->get(route('tenants.index'));

        $response->assertOk();
        $response->assertDontSee('Vermieterkunde');
    }

    public function test_a_user_cannot_open_another_users_tenant_by_guessing_the_url(): void
    {
        $owner = User::factory()->create();
        $tenant = $this->makeTenantFor($owner, 'Vermieterkunde');

        $otherUser = User::factory()->create();

        $this->actingAs($otherUser)->get(route('tenants.show', $tenant))->assertForbidden();
    }

    public function test_a_user_cannot_edit_or_delete_another_users_tenant(): void
    {
        $owner = User::factory()->create();
        $tenant = $this->makeTenantFor($owner, 'Vermieterkunde');

        $otherUser = User::factory()->create();

        $this->actingAs($otherUser)->get(route('tenants.edit', $tenant))->assertForbidden();
        $this->actingAs($otherUser)->put(route('tenants.update', $tenant), [
            'user_group_id' => $tenant->user_group_id,
            'last_name' => 'Hijacked',
            'street' => 'A', 'postal_code' => '1', 'city' => 'B',
        ])->assertForbidden();
        $this->actingAs($otherUser)->delete(route('tenants.destroy', $tenant))->assertForbidden();

        $this->assertSame('Vermieterkunde', $tenant->fresh()->last_name);
    }
}
