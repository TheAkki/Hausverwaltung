<?php

// KI-generiert

namespace Tests\Feature;

use App\Http\Controllers\ManagementGroupController;
use App\Models\BillingTemplate;
use App\Models\Group;
use App\Models\ManagementGroup;
use App\Models\User;
use App\Services\GroupOwnership\ManagementGroupTransfer;
use App\Support\FileStorage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Api\Concerns\CreatesApiFixtures;
use Tests\TestCase;

/**
 * Die Standardvorlage einer Verwaltungsgruppe ist eine eingebaute Vorlage oder gehört
 * zu ihrer Benutzergruppe; beim Gruppenwechsel kommt sie als Kopie mit.
 */
class BillingTemplateOwnershipTest extends TestCase
{
    use CreatesApiFixtures, RefreshDatabase;

    private User $user;

    private Group $shared;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake(FileStorage::diskName());
        $this->user = User::factory()->create();
        $this->shared = Group::create(['name' => 'Gemeinsam', 'owner_id' => $this->user->id]);
        $this->shared->users()->attach($this->user->id, ['role' => 'admin']);
    }

    private function template(?int $groupId, string $name, ?string $image = null): BillingTemplate
    {
        return BillingTemplate::create(['user_group_id' => $groupId, 'name' => $name, 'content' => '<p>x</p>', 'header_image_path' => $image]);
    }

    /**
     * @return array<string, mixed>
     */
    private function formFor(ManagementGroup $managementGroup, int $groupId, ?int $templateId): array
    {
        return ['description' => $managementGroup->description, 'user_group_id' => $groupId, 'default_billing_template_id' => $templateId];
    }

    public function test_the_edit_form_offers_only_visible_templates(): void
    {
        $managementGroup = $this->makeManagementGroup($this->user);
        $this->template($this->groupOf($this->user)->id, 'Eigene Vorlage');
        $this->template(null, 'Eingebaute Vorlage');
        $this->template($this->groupOf(User::factory()->create())->id, 'Fremde Vorlage');

        $this->actingAs($this->user)->get(route('management-groups.edit', $managementGroup))
            ->assertOk()->assertSee('Eigene Vorlage')->assertSee('Eingebaute Vorlage')->assertDontSee('Fremde Vorlage');
    }

    public function test_a_template_of_another_group_is_rejected_and_own_or_builtin_ones_are_accepted(): void
    {
        $managementGroup = $this->makeManagementGroup($this->user);
        $personal = $this->groupOf($this->user)->id;
        $foreign = $this->template($this->groupOf(User::factory()->create())->id, 'Fremd');
        $sharedTemplate = $this->template($this->shared->id, 'Gemeinsam');

        $this->actingAs($this->user)->put(route('management-groups.update', $managementGroup), $this->formFor($managementGroup, $personal, $foreign->id))
            ->assertSessionHasErrors('default_billing_template_id');
        $this->actingAs($this->user)->put(route('management-groups.update', $managementGroup), $this->formFor($managementGroup, $personal, $sharedTemplate->id))
            ->assertSessionHasErrors('default_billing_template_id');

        $builtin = $this->template(null, 'Eingebaut');
        $this->actingAs($this->user)->put(route('management-groups.update', $managementGroup), $this->formFor($managementGroup, $personal, $builtin->id))
            ->assertSessionHasNoErrors();
        $this->assertSame($builtin->id, $managementGroup->fresh()->default_billing_template_id);
    }

    public function test_moving_a_management_group_copies_its_template_with_its_own_header_image(): void
    {
        FileStorage::disk()->put('billing-template-images/logo.png', 'PNG');
        $template = $this->template($this->groupOf($this->user)->id, 'Hausvorlage', 'billing-template-images/logo.png');
        $managementGroup = $this->makeManagementGroup($this->user);
        $managementGroup->update(['default_billing_template_id' => $template->id]);

        // Das Formular schickt die bisherige Vorlage mit – es gilt die mitgenommene Kopie.
        $this->actingAs($this->user)->put(route('management-groups.update', $managementGroup), $this->formFor($managementGroup, $this->shared->id, $template->id))
            ->assertSessionHasNoErrors()
            ->assertSessionHas(ManagementGroupController::TRANSFER_REPORT, fn (array $lines) => str_contains(implode("\n", $lines), 'Vorlage „Hausvorlage“'));

        $copy = BillingTemplate::where('user_group_id', $this->shared->id)->sole();
        $this->assertSame($copy->id, $managementGroup->fresh()->default_billing_template_id);
        $this->assertSame('Hausvorlage', $copy->name);
        $this->assertNotSame($template->header_image_path, $copy->header_image_path);
        $this->assertSame('PNG', FileStorage::disk()->get($copy->header_image_path));
        $this->assertSame($this->groupOf($this->user)->id, $template->fresh()->user_group_id);
    }

    public function test_a_template_of_the_same_name_in_the_target_group_is_reused(): void
    {
        $template = $this->template($this->groupOf($this->user)->id, 'Hausvorlage');
        $existing = $this->template($this->shared->id, 'Hausvorlage');
        $managementGroup = $this->makeManagementGroup($this->user);
        $managementGroup->update(['default_billing_template_id' => $template->id]);

        $report = app(ManagementGroupTransfer::class)->move($managementGroup, $this->shared->id);

        $this->assertSame($existing->id, $managementGroup->fresh()->default_billing_template_id);
        $this->assertSame(['Vorlage „Hausvorlage“'], $report->reused);
        $this->assertSame(2, BillingTemplate::count());
    }

    public function test_a_builtin_template_stays_and_a_deliberate_choice_is_kept(): void
    {
        $builtin = $this->template(null, 'Eingebaut');
        $managementGroup = $this->makeManagementGroup($this->user);
        $managementGroup->update(['default_billing_template_id' => $builtin->id]);
        $transfer = app(ManagementGroupTransfer::class);

        $report = $transfer->move($managementGroup, $this->shared->id);

        $this->assertTrue($report->isEmpty());
        $this->assertSame($builtin->id, $managementGroup->fresh()->default_billing_template_id);
        $this->assertSame(['default_billing_template_id' => 7], $transfer->keepMovedTemplate(['default_billing_template_id' => 7], $builtin->id, $managementGroup));
        $this->assertSame([], $transfer->keepMovedTemplate([], $builtin->id, $managementGroup));
        $this->assertSame(['default_billing_template_id' => 7], $transfer->keepMovedTemplate(['default_billing_template_id' => 7], null, $managementGroup));
    }

    public function test_the_api_also_takes_the_template_along(): void
    {
        $template = $this->template($this->groupOf($this->user)->id, 'Hausvorlage');
        $managementGroup = $this->makeManagementGroup($this->user);
        $managementGroup->update(['default_billing_template_id' => $template->id]);
        Sanctum::actingAs($this->user);

        $response = $this->putJson("/api/management-groups/{$managementGroup->id}", $this->formFor($managementGroup, $this->shared->id, $template->id))
            ->assertOk();

        $copy = BillingTemplate::where('user_group_id', $this->shared->id)->sole();
        $response->assertJsonPath('default_billing_template_id', $copy->id)->assertJsonPath('transfer.copied.0', 'Vorlage „Hausvorlage“');
    }

    public function test_a_template_cannot_change_its_group_while_another_group_uses_it_as_default(): void
    {
        $template = $this->template($this->groupOf($this->user)->id, 'Hausvorlage');
        $managementGroup = $this->makeManagementGroup($this->user);
        $managementGroup->update(['default_billing_template_id' => $template->id]);
        $input = ['user_group_id' => $this->shared->id, 'name' => 'Hausvorlage', 'content' => '<p>x</p>'];

        $this->actingAs($this->user)->put(route('billing-templates.update', $template), $input)
            ->assertSessionHasErrors(['user_group_id' => 'Die Vorlage ist Standardvorlage von Verwaltungsgruppen einer anderen Gruppe: MG. Wähle dort zuerst eine andere Vorlage.']);

        $unused = $this->template($this->groupOf($this->user)->id, 'Frei');
        $this->actingAs($this->user)->put(route('billing-templates.update', $unused), [...$input, 'name' => 'Frei'])->assertSessionHasNoErrors();
        $this->assertSame($this->shared->id, $unused->fresh()->user_group_id);
    }
}
