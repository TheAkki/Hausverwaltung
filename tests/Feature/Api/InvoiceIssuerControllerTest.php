<?php

namespace Tests\Feature\Api;

use App\Models\InvoiceIssuer;
use App\Models\InvoiceIssuerAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Api\Concerns\CreatesApiFixtures;
use Tests\TestCase;

// KI-generiert
class InvoiceIssuerControllerTest extends TestCase
{
    use CreatesApiFixtures, RefreshDatabase;

    /**
     * @return array<string, mixed>
     */
    private function payload(User $user, array $overrides = []): array
    {
        return [
            'user_group_id' => $this->groupOf($user)->id, 'name' => 'Stadtwerke',
            'street' => 'Weg 1', 'postal_code' => '12345', 'city' => 'Stadt', ...$overrides,
        ];
    }

    public function test_an_issuer_can_be_created_listed_shown_updated_and_deleted(): void
    {
        $user = $this->apiUser();

        $id = $this->postJson(route('api.invoice-issuers.store'), $this->payload($user))->assertCreated()->json('id');

        $this->getJson(route('api.invoice-issuers.index'))->assertOk()->assertJsonPath('data.0.name', 'Stadtwerke');
        $this->getJson(route('api.invoice-issuers.show', $id))->assertOk()->assertJsonPath('accounts', []);
        $this->putJson(route('api.invoice-issuers.update', $id), $this->payload($user, ['name' => 'Neu']))
            ->assertOk()->assertJsonPath('name', 'Neu');
        $this->deleteJson(route('api.invoice-issuers.destroy', $id))->assertNoContent();
        $this->assertNull(InvoiceIssuer::find($id));
    }

    public function test_the_address_is_optional_and_stored_as_empty_string(): void
    {
        $user = $this->apiUser();

        $id = $this->postJson(route('api.invoice-issuers.store'), $this->payload($user, [
            'street' => null, 'postal_code' => '', 'city' => null,
        ]))->assertCreated()->json('id');

        $issuer = InvoiceIssuer::find($id);
        $this->assertSame(['', '', ''], [$issuer->street, $issuer->postal_code, $issuer->city]);
    }

    public function test_an_issuer_cannot_be_created_in_a_foreign_group(): void
    {
        $this->apiUser();
        $foreign = User::factory()->create();

        $this->postJson(route('api.invoice-issuers.store'), $this->payload($foreign))->assertForbidden();
    }

    public function test_an_issuer_cannot_be_moved_to_a_foreign_group(): void
    {
        $user = $this->apiUser();
        $issuer = $this->makeIssuer($user);

        $this->putJson(route('api.invoice-issuers.update', $issuer), $this->payload(User::factory()->create()))->assertForbidden();
    }

    public function test_customer_accounts_can_be_created_updated_and_deleted(): void
    {
        $user = $this->apiUser();
        $issuer = $this->makeIssuer($user);

        $id = $this->postJson(route('api.invoice-issuer-accounts.store'), [
            'invoice_issuer_id' => $issuer->id, 'customer_number' => '4711', 'direct_debit' => true,
        ])->assertCreated()->assertJsonPath('direct_debit', true)->json('id');

        $this->putJson(route('api.invoice-issuer-accounts.update', $id), ['customer_number' => '0815'])
            ->assertOk()->assertJsonPath('customer_number', '0815')->assertJsonPath('direct_debit', false);
        $this->deleteJson(route('api.invoice-issuer-accounts.destroy', $id))->assertNoContent();
        $this->assertNull(InvoiceIssuerAccount::find($id));
    }

    public function test_customer_accounts_of_foreign_issuers_are_inaccessible(): void
    {
        $this->apiUser();
        $issuer = $this->makeIssuer(User::factory()->create());
        $account = $issuer->accounts()->create(['customer_number' => '1']);

        $this->postJson(route('api.invoice-issuer-accounts.store'), ['invoice_issuer_id' => $issuer->id, 'customer_number' => '2'])
            ->assertNotFound();
        $this->putJson(route('api.invoice-issuer-accounts.update', $account), ['customer_number' => '3'])->assertForbidden();
        $this->deleteJson(route('api.invoice-issuer-accounts.destroy', $account))->assertForbidden();
    }
}
