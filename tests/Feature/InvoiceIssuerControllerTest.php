<?php

namespace Tests\Feature;

use App\Models\InvoiceIssuer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Covers CRUD for Rechnungssteller, including the Vorname field (a person's first
 * name, kept separate from the required Name/Firma field).
 */
class InvoiceIssuerControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_show_page_displays_the_iban_grouped_in_4_character_blocks(): void
    {
        $user = User::factory()->create();
        $group = $user->groups()->first();
        $issuer = InvoiceIssuer::create([
            'user_group_id' => $group->id, 'name' => 'Stadtwerke',
            'street' => 'A', 'postal_code' => '1', 'city' => 'B',
            'iban' => 'DE46850501000232067414',
        ]);

        $response = $this->actingAs($user)->get(route('invoice-issuers.show', $issuer));

        $response->assertOk();
        $response->assertSee('DE46 8505 0100 0232 0674 14');
    }

    public function test_the_create_form_includes_a_vorname_field(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get(route('invoice-issuers.create'));

        $response->assertOk();
        $response->assertSee('name="first_name"', false);
    }

    public function test_an_issuer_can_be_created_with_a_vorname(): void
    {
        $user = User::factory()->create();
        $group = $user->groups()->first();

        $response = $this->actingAs($user)->post(route('invoice-issuers.store'), [
            'user_group_id' => $group->id,
            'first_name' => 'Max',
            'name' => 'Mustermann',
            'street' => 'A', 'postal_code' => '1', 'city' => 'B',
        ]);

        $issuer = InvoiceIssuer::where('name', 'Mustermann')->first();
        $this->assertNotNull($issuer);
        $response->assertRedirect(route('invoice-issuers.show', $issuer));
        $this->assertSame('Max', $issuer->first_name);
        $this->assertSame('Max Mustermann', $issuer->fullName());
    }

    public function test_an_issuer_without_a_vorname_still_has_a_usable_full_name(): void
    {
        $user = User::factory()->create();
        $group = $user->groups()->first();

        $this->actingAs($user)->post(route('invoice-issuers.store'), [
            'user_group_id' => $group->id,
            'company_name' => 'Stadtwerke GmbH',
            'name' => 'Stadtwerke GmbH',
            'street' => 'A', 'postal_code' => '1', 'city' => 'B',
        ]);

        $issuer = InvoiceIssuer::where('name', 'Stadtwerke GmbH')->first();
        $this->assertNotNull($issuer);
        $this->assertNull($issuer->first_name);
        $this->assertSame('Stadtwerke GmbH', $issuer->fullName());
    }

    public function test_an_issuer_cannot_be_created_for_a_group_the_user_does_not_belong_to(): void
    {
        $user = User::factory()->create();
        $outsider = User::factory()->create();
        $outsiderGroup = $outsider->groups()->first();

        $response = $this->actingAs($user)->post(route('invoice-issuers.store'), [
            'user_group_id' => $outsiderGroup->id,
            'name' => 'Mustermann',
            'street' => 'A', 'postal_code' => '1', 'city' => 'B',
        ]);

        $response->assertForbidden();
        $this->assertSame(0, InvoiceIssuer::count());
    }

    public function test_the_index_page_can_be_rendered(): void
    {
        $user = User::factory()->create();
        $group = $user->groups()->first();
        InvoiceIssuer::create(['user_group_id' => $group->id, 'name' => 'Stadtwerke', 'street' => 'A', 'postal_code' => '1', 'city' => 'B']);

        $response = $this->actingAs($user)->get(route('invoice-issuers.index'));

        $response->assertOk();
        $response->assertSee('Stadtwerke');
    }

    public function test_the_show_page_can_be_rendered_by_the_owning_group(): void
    {
        $user = User::factory()->create();
        $group = $user->groups()->first();
        $issuer = InvoiceIssuer::create(['user_group_id' => $group->id, 'name' => 'Stadtwerke', 'street' => 'A', 'postal_code' => '1', 'city' => 'B']);

        $response = $this->actingAs($user)->get(route('invoice-issuers.show', $issuer));

        $response->assertOk();
    }

    public function test_an_issuer_can_be_updated(): void
    {
        $user = User::factory()->create();
        $group = $user->groups()->first();
        $issuer = InvoiceIssuer::create(['user_group_id' => $group->id, 'name' => 'Stadtwerke', 'street' => 'A', 'postal_code' => '1', 'city' => 'B']);

        $response = $this->actingAs($user)->put(route('invoice-issuers.update', $issuer), [
            'user_group_id' => $group->id,
            'name' => 'Stadtwerke AG',
            'street' => 'Neue Str 1', 'postal_code' => '2', 'city' => 'C',
        ]);

        $response->assertRedirect(route('invoice-issuers.show', $issuer));
        $this->assertSame('Stadtwerke AG', $issuer->fresh()->name);
    }

    public function test_an_issuer_cannot_be_moved_to_a_group_the_user_does_not_belong_to(): void
    {
        $user = User::factory()->create();
        $group = $user->groups()->first();
        $issuer = InvoiceIssuer::create(['user_group_id' => $group->id, 'name' => 'Stadtwerke', 'street' => 'A', 'postal_code' => '1', 'city' => 'B']);

        $otherUser = User::factory()->create();
        $otherGroup = $otherUser->groups()->first();

        $response = $this->actingAs($user)->put(route('invoice-issuers.update', $issuer), [
            'user_group_id' => $otherGroup->id,
            'name' => 'Hijacked',
            'street' => 'A', 'postal_code' => '1', 'city' => 'B',
        ]);

        $response->assertForbidden();
        $this->assertSame('Stadtwerke', $issuer->fresh()->name);
    }

    public function test_an_issuer_can_be_deleted(): void
    {
        $user = User::factory()->create();
        $group = $user->groups()->first();
        $issuer = InvoiceIssuer::create(['user_group_id' => $group->id, 'name' => 'Stadtwerke', 'street' => 'A', 'postal_code' => '1', 'city' => 'B']);

        $response = $this->actingAs($user)->delete(route('invoice-issuers.destroy', $issuer));

        $response->assertRedirect(route('invoice-issuers.index'));
        $this->assertNull(InvoiceIssuer::find($issuer->id));
    }
}
