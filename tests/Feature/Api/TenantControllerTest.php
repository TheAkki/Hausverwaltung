<?php

namespace Tests\Feature\Api;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Api\Concerns\CreatesApiFixtures;
use Tests\TestCase;

// KI-generiert
class TenantControllerTest extends TestCase
{
    use CreatesApiFixtures, RefreshDatabase;

    /**
     * @return array<string, mixed>
     */
    private function payload(User $user, array $overrides = []): array
    {
        return [
            'user_group_id' => $this->groupOf($user)->id, 'last_name' => 'Neu',
            'street' => 'Weg 1', 'postal_code' => '12345', 'city' => 'Stadt', ...$overrides,
        ];
    }

    public function test_the_index_only_lists_tenants_of_the_users_groups(): void
    {
        $user = $this->apiUser();
        $this->makeTenant($user);
        $this->makeTenant(User::factory()->create(), 'Fremd');

        $response = $this->getJson(route('api.tenants.index'));

        $response->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.last_name', 'Mustermann');
    }

    public function test_a_tenant_can_be_created(): void
    {
        $user = $this->apiUser();

        $response = $this->postJson(route('api.tenants.store'), $this->payload($user));

        $response->assertCreated()->assertJsonPath('last_name', 'Neu');
        $this->assertSame(1, Tenant::count());
    }

    public function test_a_tenant_cannot_be_created_for_a_foreign_group(): void
    {
        $this->apiUser();
        $outsider = User::factory()->create();

        $this->postJson(route('api.tenants.store'), $this->payload($outsider))->assertForbidden();
        $this->assertSame(0, Tenant::count());
    }

    public function test_an_invalid_iban_is_rejected(): void
    {
        $user = $this->apiUser();

        $response = $this->postJson(route('api.tenants.store'), $this->payload($user, ['iban' => 'DE00123']));

        $response->assertUnprocessable()->assertJsonValidationErrors('iban');
    }

    public function test_a_tenant_can_be_shown_with_its_leases(): void
    {
        $user = $this->apiUser();
        $lease = $this->makeLease($user);
        $tenant = $lease->tenants->first();

        $response = $this->getJson(route('api.tenants.show', $tenant));

        $response->assertOk()->assertJsonPath('leases.0.id', $lease->id);
    }

    public function test_a_foreign_tenant_cannot_be_shown(): void
    {
        $tenant = $this->makeTenant(User::factory()->create());
        $this->apiUser();

        $this->getJson(route('api.tenants.show', $tenant))->assertForbidden();
    }

    public function test_a_tenant_can_be_updated(): void
    {
        $user = $this->apiUser();
        $tenant = $this->makeTenant($user);

        $response = $this->putJson(route('api.tenants.update', $tenant), $this->payload($user, ['last_name' => 'Geändert']));

        $response->assertOk()->assertJsonPath('last_name', 'Geändert');
    }

    public function test_a_tenant_cannot_be_moved_to_a_foreign_group(): void
    {
        $user = $this->apiUser();
        $tenant = $this->makeTenant($user);

        $response = $this->putJson(route('api.tenants.update', $tenant), $this->payload(User::factory()->create()));

        $response->assertForbidden();
        $this->assertSame($this->groupOf($user)->id, $tenant->fresh()->user_group_id);
    }

    public function test_a_tenant_can_be_deleted(): void
    {
        $user = $this->apiUser();
        $tenant = $this->makeTenant($user);

        $this->deleteJson(route('api.tenants.destroy', $tenant))->assertNoContent();
        $this->assertNull(Tenant::find($tenant->id));
    }

    public function test_a_foreign_tenant_cannot_be_deleted(): void
    {
        $tenant = $this->makeTenant(User::factory()->create());
        Sanctum::actingAs(User::factory()->create());

        $this->deleteJson(route('api.tenants.destroy', $tenant))->assertForbidden();
        $this->assertNotNull(Tenant::find($tenant->id));
    }
}
