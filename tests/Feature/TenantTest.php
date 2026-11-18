<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TenantTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_show_page_displays_the_iban_grouped_in_4_character_blocks(): void
    {
        $user = User::factory()->create();
        $group = $user->groups()->first();
        $tenant = Tenant::create([
            'user_group_id' => $group->id, 'last_name' => 'Mustermann',
            'street' => 'A', 'postal_code' => '1', 'city' => 'B',
            'iban' => 'DE46850501000232067414',
        ]);

        $response = $this->actingAs($user)->get(route('tenants.show', $tenant));

        $response->assertOk();
        $response->assertSee('DE46 8505 0100 0232 0674 14');
    }

    public function test_the_create_form_can_be_rendered(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get(route('tenants.create'));

        $response->assertOk();
    }

    public function test_the_edit_form_can_be_rendered(): void
    {
        $user = User::factory()->create();
        $group = $user->groups()->first();
        $tenant = Tenant::create([
            'user_group_id' => $group->id, 'last_name' => 'Mustermann',
            'street' => 'A', 'postal_code' => '1', 'city' => 'B',
        ]);

        $response = $this->actingAs($user)->get(route('tenants.edit', $tenant));

        $response->assertOk();
    }

    public function test_a_tenant_can_be_deleted(): void
    {
        $user = User::factory()->create();
        $group = $user->groups()->first();
        $tenant = Tenant::create([
            'user_group_id' => $group->id, 'last_name' => 'Mustermann',
            'street' => 'A', 'postal_code' => '1', 'city' => 'B',
        ]);

        $response = $this->actingAs($user)->delete(route('tenants.destroy', $tenant));

        $response->assertRedirect(route('tenants.index'));
        $this->assertNull(Tenant::find($tenant->id));
    }

    public function test_a_tenants_salutation_can_be_stored_updated_and_is_displayed(): void
    {
        $user = User::factory()->create();
        $group = $user->groups()->first();

        $response = $this->actingAs($user)->post('/tenants', [
            'user_group_id' => $group->id,
            'salutation' => 'Frau',
            'last_name' => 'Musterfrau',
            'first_name' => 'Erika',
            'street' => 'Musterweg 1',
            'postal_code' => '12345',
            'city' => 'Musterstadt',
        ]);

        $tenant = Tenant::first();
        $response->assertRedirect(route('tenants.show', $tenant));
        $this->assertSame('Frau', $tenant->salutation);

        $show = $this->actingAs($user)->get(route('tenants.show', $tenant));
        $show->assertSee('Frau');

        $this->actingAs($user)->put(route('tenants.update', $tenant), [
            'user_group_id' => $group->id,
            'salutation' => 'Herr',
            'last_name' => 'Musterfrau',
            'street' => 'Musterweg 1',
            'postal_code' => '12345',
            'city' => 'Musterstadt',
        ]);

        $this->assertSame('Herr', $tenant->fresh()->salutation);
    }

    public function test_an_invalid_salutation_is_rejected(): void
    {
        $user = User::factory()->create();
        $group = $user->groups()->first();

        $response = $this->actingAs($user)->post('/tenants', [
            'user_group_id' => $group->id,
            'salutation' => 'Doktor',
            'last_name' => 'Mustermann',
            'street' => 'Musterweg 1',
            'postal_code' => '12345',
            'city' => 'Musterstadt',
        ]);

        $response->assertSessionHasErrors('salutation');
        $this->assertSame(0, Tenant::count());
    }

    public function test_multiline_notes_can_be_stored_and_are_displayed(): void
    {
        $user = User::factory()->create();
        $group = $user->groups()->first();

        $notes = "Erster Hinweis.\nZweiter Hinweis.\nDritter Hinweis.";

        $response = $this->actingAs($user)->post('/tenants', [
            'user_group_id' => $group->id,
            'last_name' => 'Mustermann',
            'first_name' => 'Max',
            'street' => 'Musterweg 1',
            'postal_code' => '12345',
            'city' => 'Musterstadt',
            'notes' => $notes,
        ]);

        $tenant = Tenant::first();
        $response->assertRedirect(route('tenants.show', $tenant));
        $this->assertSame($notes, $tenant->notes);

        $show = $this->actingAs($user)->get(route('tenants.show', $tenant));
        $show->assertSee('Erster Hinweis.');
        $show->assertSee('Dritter Hinweis.');
    }

    public function test_notes_can_be_updated(): void
    {
        $user = User::factory()->create();
        $group = $user->groups()->first();
        $tenant = Tenant::create([
            'user_group_id' => $group->id,
            'last_name' => 'Mustermann', 'street' => 'A', 'postal_code' => '1', 'city' => 'B',
        ]);

        $this->actingAs($user)->put(route('tenants.update', $tenant), [
            'user_group_id' => $group->id,
            'last_name' => 'Mustermann',
            'street' => 'A',
            'postal_code' => '1',
            'city' => 'B',
            'notes' => 'Aktualisierter Hinweis',
        ]);

        $this->assertSame('Aktualisierter Hinweis', $tenant->fresh()->notes);
    }

    public function test_an_invalid_iban_checksum_is_rejected(): void
    {
        $user = User::factory()->create();
        $group = $user->groups()->first();

        $response = $this->actingAs($user)->post('/tenants', [
            'user_group_id' => $group->id,
            'last_name' => 'Mustermann',
            'street' => 'A', 'postal_code' => '1', 'city' => 'B',
            'iban' => 'DE89370400440532013001',
        ]);

        $response->assertSessionHasErrors('iban');
        $this->assertSame(0, Tenant::count());
    }

    public function test_a_valid_iban_is_accepted(): void
    {
        $user = User::factory()->create();
        $group = $user->groups()->first();

        $response = $this->actingAs($user)->post('/tenants', [
            'user_group_id' => $group->id,
            'last_name' => 'Mustermann',
            'street' => 'A', 'postal_code' => '1', 'city' => 'B',
            'iban' => 'DE89 3704 0044 0532 0130 00',
        ]);

        $tenant = Tenant::first();
        $response->assertRedirect(route('tenants.show', $tenant));
        $this->assertSame('DE89 3704 0044 0532 0130 00', $tenant->iban);
    }
}
