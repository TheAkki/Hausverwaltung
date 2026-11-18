<?php

namespace Tests\Feature;

use App\Models\InvoiceIssuer;
use App\Models\InvoiceIssuerAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Covers CRUD for Kundenaccounts (customer accounts) belonging to a Rechnungssteller,
 * previously only creatable via the seeddata.yaml seeder.
 */
class InvoiceIssuerAccountControllerTest extends TestCase
{
    use RefreshDatabase;

    private function makeIssuer(User $user): InvoiceIssuer
    {
        $group = $user->groups()->first();

        return InvoiceIssuer::create([
            'user_group_id' => $group->id,
            'name' => 'Stadtwerke',
            'street' => 'A', 'postal_code' => '1', 'city' => 'B',
        ]);
    }

    public function test_the_create_form_can_be_rendered_for_a_visible_issuer(): void
    {
        $user = User::factory()->create();
        $issuer = $this->makeIssuer($user);

        $response = $this->actingAs($user)->get(route('invoice-issuer-accounts.create', ['invoice_issuer_id' => $issuer->id]));

        $response->assertOk();
    }

    public function test_the_create_form_is_forbidden_for_an_issuer_of_another_group(): void
    {
        $owner = User::factory()->create();
        $issuer = $this->makeIssuer($owner);
        $outsider = User::factory()->create();

        $response = $this->actingAs($outsider)->get(route('invoice-issuer-accounts.create', ['invoice_issuer_id' => $issuer->id]));

        $response->assertNotFound();
    }

    public function test_the_edit_form_can_be_rendered_by_the_owning_group(): void
    {
        $user = User::factory()->create();
        $issuer = $this->makeIssuer($user);
        $account = $issuer->accounts()->create(['customer_number' => '123']);

        $response = $this->actingAs($user)->get(route('invoice-issuer-accounts.edit', $account));

        $response->assertOk();
    }

    public function test_a_kundenaccount_can_be_created(): void
    {
        $user = User::factory()->create();
        $issuer = $this->makeIssuer($user);

        $response = $this->actingAs($user)->post(route('invoice-issuer-accounts.store'), [
            'invoice_issuer_id' => $issuer->id,
            'customer_number' => '12345',
            'description' => 'Strom',
            'direct_debit' => '1',
        ]);

        $account = InvoiceIssuerAccount::where('customer_number', '12345')->first();
        $this->assertNotNull($account);
        $response->assertRedirect(route('invoice-issuers.show', $issuer));
        $this->assertSame('Strom', $account->description);
        $this->assertTrue($account->direct_debit);
    }

    public function test_a_kundenaccounts_notes_can_be_set_and_updated(): void
    {
        $user = User::factory()->create();
        $issuer = $this->makeIssuer($user);

        $this->actingAs($user)->post(route('invoice-issuer-accounts.store'), [
            'invoice_issuer_id' => $issuer->id,
            'customer_number' => '12345',
            'notes' => 'IBAN: DE25 8207 0324 0415 820001',
        ]);

        $account = InvoiceIssuerAccount::where('customer_number', '12345')->first();
        $this->assertNotNull($account);
        $this->assertSame('IBAN: DE25 8207 0324 0415 820001', $account->notes);

        $this->actingAs($user)->put(route('invoice-issuer-accounts.update', $account), [
            'customer_number' => '12345',
            'notes' => 'Kassenzeichen 01-10537589-002-0001',
        ]);

        $this->assertSame('Kassenzeichen 01-10537589-002-0001', $account->fresh()->notes);
    }

    public function test_a_user_outside_the_group_cannot_create_a_kundenaccount(): void
    {
        $owner = User::factory()->create();
        $issuer = $this->makeIssuer($owner);

        $outsider = User::factory()->create();

        $response = $this->actingAs($outsider)->post(route('invoice-issuer-accounts.store'), [
            'invoice_issuer_id' => $issuer->id,
            'customer_number' => '12345',
        ]);

        $response->assertNotFound();
        $this->assertSame(0, InvoiceIssuerAccount::count());
    }

    public function test_a_kundenaccount_can_be_updated(): void
    {
        $user = User::factory()->create();
        $issuer = $this->makeIssuer($user);
        $account = $issuer->accounts()->create(['customer_number' => '111']);

        $response = $this->actingAs($user)->put(route('invoice-issuer-accounts.update', $account), [
            'customer_number' => '222',
            'description' => 'Wasser',
        ]);

        $response->assertRedirect(route('invoice-issuers.show', $issuer));
        $this->assertSame('222', $account->fresh()->customer_number);
        $this->assertSame('Wasser', $account->fresh()->description);
    }

    public function test_a_user_outside_the_group_cannot_update_a_kundenaccount(): void
    {
        $owner = User::factory()->create();
        $issuer = $this->makeIssuer($owner);
        $account = $issuer->accounts()->create(['customer_number' => '111']);

        $outsider = User::factory()->create();

        $response = $this->actingAs($outsider)->put(route('invoice-issuer-accounts.update', $account), [
            'customer_number' => 'hijacked',
        ]);

        $response->assertForbidden();
        $this->assertSame('111', $account->fresh()->customer_number);
    }

    public function test_a_kundenaccount_can_be_deleted(): void
    {
        $user = User::factory()->create();
        $issuer = $this->makeIssuer($user);
        $account = $issuer->accounts()->create(['customer_number' => '111']);

        $response = $this->actingAs($user)->delete(route('invoice-issuer-accounts.destroy', $account));

        $response->assertRedirect(route('invoice-issuers.show', $issuer));
        $this->assertNull(InvoiceIssuerAccount::find($account->id));
    }
}
