<?php

// KI-generiert

namespace Tests\Feature;

use App\Http\Controllers\InvoiceIssuerController;
use App\Models\Group;
use App\Models\InvoiceIssuer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Api\Concerns\CreatesApiFixtures;
use Tests\TestCase;

/**
 * „Rechnungssteller in andere Gruppe kopieren“ in Weboberfläche und API.
 */
class InvoiceIssuerCopyTest extends TestCase
{
    use CreatesApiFixtures, RefreshDatabase;

    private User $user;

    private Group $shared;

    private InvoiceIssuer $issuer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
        $this->shared = Group::create(['name' => 'Gemeinsam', 'owner_id' => $this->user->id]);
        $this->shared->users()->attach($this->user->id, ['role' => 'admin']);
        $this->issuer = $this->makeIssuer($this->user);
        $this->issuer->accounts()->create(['customer_number' => 'W-1']);
    }

    public function test_the_web_page_copies_the_issuer_with_its_accounts(): void
    {
        $this->actingAs($this->user)->get(route('invoice-issuers.show', $this->issuer))
            ->assertOk()->assertSee('data-copy-form', false)->assertSee('Gemeinsam');

        $response = $this->actingAs($this->user)->post(route('invoice-issuers.copy', $this->issuer), ['user_group_id' => $this->shared->id]);

        $copy = InvoiceIssuer::where('user_group_id', $this->shared->id)->sole();
        $response->assertRedirect(route('invoice-issuers.show', $copy))
            ->assertSessionHas(InvoiceIssuerController::COPY_STATUS, 'Der Rechnungssteller wurde mit seinen Kundenaccounts in diese Gruppe kopiert.');
        $this->assertSame(['W-1'], $copy->accounts()->pluck('customer_number')->all());

        $this->actingAs($this->user)->followingRedirects()
            ->post(route('invoice-issuers.copy', $this->issuer), ['user_group_id' => $this->shared->id])
            ->assertOk()->assertSee('data-copy-status', false)
            ->assertSee('Der Rechnungssteller existierte in dieser Gruppe schon; fehlende Kundenaccounts wurden ergänzt.');
        $this->assertSame(1, InvoiceIssuer::where('user_group_id', $this->shared->id)->count());
    }

    public function test_the_copy_form_is_hidden_without_another_group(): void
    {
        $loner = User::factory()->create();

        $this->actingAs($loner)->get(route('invoice-issuers.show', $this->makeIssuer($loner)))
            ->assertOk()->assertDontSee('data-copy-form', false);
    }

    public function test_the_own_group_and_foreign_groups_are_rejected(): void
    {
        $foreign = User::factory()->create()->groups()->first();

        $this->actingAs($this->user)->post(route('invoice-issuers.copy', $this->issuer), ['user_group_id' => $this->groupOf($this->user)->id])
            ->assertSessionHasErrors(['user_group_id' => 'Der Rechnungssteller gehört bereits zu dieser Gruppe.']);
        $this->actingAs($this->user)->post(route('invoice-issuers.copy', $this->issuer), ['user_group_id' => $foreign->id])
            ->assertSessionHasErrors(['user_group_id' => 'Wähle eine Gruppe, in der du Mitglied bist.']);
        $this->assertSame(1, InvoiceIssuer::count());
    }

    public function test_an_issuer_the_user_cannot_see_cannot_be_copied(): void
    {
        $stranger = User::factory()->create();

        $this->actingAs($stranger)->post(route('invoice-issuers.copy', $this->issuer), ['user_group_id' => $stranger->groups()->first()->id])
            ->assertForbidden();
    }

    public function test_the_api_answers_201_for_a_copy_and_200_for_an_existing_one(): void
    {
        Sanctum::actingAs($this->user);

        $created = $this->postJson(route('api.invoice-issuers.copy', $this->issuer), ['user_group_id' => $this->shared->id])
            ->assertCreated()->assertJsonPath('user_group_id', $this->shared->id)->assertJsonPath('accounts.0.customer_number', 'W-1');

        $this->postJson(route('api.invoice-issuers.copy', $this->issuer), ['user_group_id' => $this->shared->id])
            ->assertOk()->assertJsonPath('id', $created->json('id'));
        $this->postJson(route('api.invoice-issuers.copy', $this->issuer), [])
            ->assertUnprocessable()->assertJsonValidationErrors('user_group_id');
    }
}
