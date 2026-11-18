<?php

namespace Tests\Feature;

use App\Models\BillingTemplate;
use App\Models\ManagementGroup;
use App\Models\User;
use Database\Seeders\BillingTemplateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Covers CRUD for Betriebskostenabrechnung templates, including header image
 * upload/replacement/removal, duplication, group scoping/visibility and the
 * "Standard" (default-for-group) behavior.
 */
class BillingTemplateControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_template_can_be_created_and_assigned_to_the_users_own_group(): void
    {
        $user = User::factory()->create();
        $group = $user->groups()->first();

        $response = $this->actingAs($user)->post(route('billing-templates.store'), [
            'user_group_id' => $group->id,
            'name' => 'Mein Template',
            'content' => '<p>{{ $tenant->fullName() }}</p>',
        ]);

        $template = BillingTemplate::where('name', 'Mein Template')->first();
        $this->assertNotNull($template);
        $response->assertRedirect(route('billing-templates.show', $template));
        $this->assertFalse($template->is_default);
        $this->assertSame($group->id, $template->user_group_id);
    }

    /**
     * Konzept.md "### Template": "Der Content soll von der Formatierung getrennt
     * sein." – "CSS-Content" wird wie Header/Content/Footer eigenständig gespeichert.
     */
    public function test_a_templates_css_content_is_saved_separately_from_its_content(): void
    {
        $user = User::factory()->create();
        $group = $user->groups()->first();

        $this->actingAs($user)->post(route('billing-templates.store'), [
            'user_group_id' => $group->id,
            'name' => 'Mit CSS',
            'content' => '<p>Inhalt</p>',
            'css_content' => 'body { color: red; }',
        ]);

        $template = BillingTemplate::where('name', 'Mit CSS')->first();
        $this->assertNotNull($template);
        $this->assertSame('body { color: red; }', $template->css_content);

        $this->actingAs($user)->put(route('billing-templates.update', $template), [
            'user_group_id' => $group->id,
            'name' => 'Mit CSS',
            'content' => '<p>Inhalt</p>',
            'css_content' => 'body { color: blue; }',
        ]);

        $this->assertSame('body { color: blue; }', $template->fresh()->css_content);
    }

    public function test_a_template_cannot_be_assigned_to_a_group_the_user_is_not_a_member_of(): void
    {
        $user = User::factory()->create();
        $outsider = User::factory()->create();
        $foreignGroup = $outsider->groups()->first();

        $response = $this->actingAs($user)->post(route('billing-templates.store'), [
            'user_group_id' => $foreignGroup->id,
            'name' => 'Fremdes Template',
            'content' => 'Inhalt',
        ]);

        $response->assertSessionHasErrors('user_group_id');
        $this->assertSame(0, BillingTemplate::count());
    }

    public function test_a_template_can_be_created_with_a_header_image(): void
    {
        Storage::fake('public');
        $user = User::factory()->create();
        $group = $user->groups()->first();

        $response = $this->actingAs($user)->post(route('billing-templates.store'), [
            'user_group_id' => $group->id,
            'name' => 'Mit Bild',
            'content' => 'Inhalt',
            'header_image' => UploadedFile::fake()->create('logo.png', 10, 'image/png'),
        ]);

        $template = BillingTemplate::where('name', 'Mit Bild')->first();
        $this->assertNotNull($template->header_image_path);
        Storage::disk('public')->assertExists($template->header_image_path);
        $response->assertRedirect(route('billing-templates.show', $template));
    }

    public function test_creating_a_template_without_content_fails_validation(): void
    {
        $user = User::factory()->create();
        $group = $user->groups()->first();

        $response = $this->actingAs($user)->post(route('billing-templates.store'), [
            'user_group_id' => $group->id,
            'name' => 'Ohne Inhalt',
        ]);

        $response->assertSessionHasErrors('content');
        $this->assertSame(0, BillingTemplate::count());
    }

    /**
     * "Standard" for a self-created (group-scoped) template means "default template
     * for this group's Verwaltungsgruppen" (default_billing_template_id), not the
     * model's own global `is_default` flag, which stays reserved for the one built-in
     * template without a group.
     */
    public function test_marking_a_template_as_standard_makes_it_the_default_for_the_groups_management_groups(): void
    {
        $user = User::factory()->create();
        $group = $user->groups()->first();
        $managementGroup = ManagementGroup::create(['description' => 'MG', 'user_group_id' => $group->id]);
        $otherUser = User::factory()->create();
        $otherManagementGroup = ManagementGroup::create(['description' => 'Andere Gruppe', 'user_group_id' => $otherUser->groups()->first()->id]);

        $response = $this->actingAs($user)->post(route('billing-templates.store'), [
            'user_group_id' => $group->id,
            'name' => 'Standard-Template',
            'content' => 'Inhalt',
            'is_default' => '1',
        ]);

        $template = BillingTemplate::where('name', 'Standard-Template')->first();
        $response->assertRedirect(route('billing-templates.show', $template));
        $this->assertFalse($template->is_default);
        $this->assertSame($template->id, $managementGroup->fresh()->default_billing_template_id);
        $this->assertNull($otherManagementGroup->fresh()->default_billing_template_id);
    }

    public function test_the_create_show_and_edit_pages_can_be_rendered(): void
    {
        $user = User::factory()->create();
        $group = $user->groups()->first();
        $template = BillingTemplate::create(['user_group_id' => $group->id, 'name' => 'Test', 'content' => 'Inhalt']);

        $this->actingAs($user)->get(route('billing-templates.create'))->assertOk();
        $this->actingAs($user)->get(route('billing-templates.show', $template))->assertOk();
        $this->actingAs($user)->get(route('billing-templates.edit', $template))->assertOk();
    }

    public function test_marking_an_existing_template_as_standard_on_update_makes_it_the_groups_default(): void
    {
        $user = User::factory()->create();
        $group = $user->groups()->first();
        $managementGroup = ManagementGroup::create(['description' => 'MG', 'user_group_id' => $group->id]);
        $template = BillingTemplate::create(['user_group_id' => $group->id, 'name' => 'Test', 'content' => 'Inhalt']);

        $this->actingAs($user)->put(route('billing-templates.update', $template), [
            'user_group_id' => $group->id,
            'name' => 'Test',
            'content' => 'Inhalt',
            'is_default' => '1',
        ]);

        $this->assertSame($template->id, $managementGroup->fresh()->default_billing_template_id);
    }

    public function test_replacing_the_header_image_deletes_the_old_one(): void
    {
        Storage::fake('public');
        $user = User::factory()->create();
        $group = $user->groups()->first();
        $oldPath = UploadedFile::fake()->create('old.png', 10, 'image/png')->store('billing-template-images', 'public');
        $template = BillingTemplate::create([
            'user_group_id' => $group->id, 'name' => 'Test', 'content' => 'Inhalt', 'header_image_path' => $oldPath,
        ]);

        $this->actingAs($user)->put(route('billing-templates.update', $template), [
            'user_group_id' => $group->id,
            'name' => 'Test',
            'content' => 'Inhalt',
            'header_image' => UploadedFile::fake()->create('new.png', 10, 'image/png'),
        ]);

        Storage::disk('public')->assertMissing($oldPath);
        Storage::disk('public')->assertExists($template->fresh()->header_image_path);
        $this->assertNotSame($oldPath, $template->fresh()->header_image_path);
    }

    public function test_the_header_image_can_be_removed_without_replacement(): void
    {
        Storage::fake('public');
        $user = User::factory()->create();
        $group = $user->groups()->first();
        $oldPath = UploadedFile::fake()->create('old.png', 10, 'image/png')->store('billing-template-images', 'public');
        $template = BillingTemplate::create([
            'user_group_id' => $group->id, 'name' => 'Test', 'content' => 'Inhalt', 'header_image_path' => $oldPath,
        ]);

        $this->actingAs($user)->put(route('billing-templates.update', $template), [
            'user_group_id' => $group->id,
            'name' => 'Test',
            'content' => 'Inhalt',
            'remove_header_image' => '1',
        ]);

        Storage::disk('public')->assertMissing($oldPath);
        $this->assertNull($template->fresh()->header_image_path);
    }

    public function test_a_user_cannot_edit_a_template_belonging_to_a_group_they_are_not_a_member_of(): void
    {
        $user = User::factory()->create();
        $owner = User::factory()->create();
        $foreignGroup = $owner->groups()->first();
        $template = BillingTemplate::create(['user_group_id' => $foreignGroup->id, 'name' => 'Fremd', 'content' => 'Inhalt']);

        $response = $this->actingAs($user)->put(route('billing-templates.update', $template), [
            'user_group_id' => $foreignGroup->id, 'name' => 'Fremd', 'content' => 'Geändert',
        ]);

        $response->assertForbidden();
    }

    /**
     * The built-in/"internal" template (no group) is visible to and duplicable by
     * everyone, but not directly editable – it's meant to be copied, not modified.
     */
    public function test_the_internal_template_without_a_group_cannot_be_edited_or_deleted(): void
    {
        $user = User::factory()->create();
        $internal = BillingTemplate::create(['name' => 'Standard (DIN 5008)', 'content' => 'Inhalt', 'is_default' => true]);

        $this->actingAs($user)->put(route('billing-templates.update', $internal), [
            'user_group_id' => $user->groups()->first()->id, 'name' => 'Standard (DIN 5008)', 'content' => 'Geändert',
        ])->assertForbidden();

        $this->actingAs($user)->delete(route('billing-templates.destroy', $internal))->assertForbidden();
    }

    public function test_a_template_can_be_duplicated_including_its_header_image(): void
    {
        Storage::fake('public');
        $user = User::factory()->create();
        $originalPath = UploadedFile::fake()->create('logo.png', 10, 'image/png')->store('billing-template-images', 'public');
        $template = BillingTemplate::create([
            'name' => 'Original', 'content' => 'Inhalt', 'header_image_path' => $originalPath, 'is_default' => true,
        ]);

        $response = $this->actingAs($user)->post(route('billing-templates.duplicate', $template));

        $copy = BillingTemplate::where('name', 'Original (Kopie)')->first();
        $this->assertNotNull($copy);
        $response->assertRedirect(route('billing-templates.show', $copy));
        $this->assertFalse($copy->is_default);
        // Die Kopie eines internen Templates wird selbst zu einem "selbst erstellten"
        // Template und daher der eigenen Gruppe des duplizierenden Nutzers zugewiesen.
        $this->assertSame($user->groups()->first()->id, $copy->user_group_id);
        $this->assertNotSame($originalPath, $copy->header_image_path);
        Storage::disk('public')->assertExists($copy->header_image_path);
    }

    public function test_duplicating_a_template_copies_its_css_content(): void
    {
        $user = User::factory()->create();
        $template = BillingTemplate::create([
            'name' => 'Original', 'content' => 'Inhalt', 'css_content' => 'body { color: red; }', 'is_default' => true,
        ]);

        $this->actingAs($user)->post(route('billing-templates.duplicate', $template));

        $copy = BillingTemplate::where('name', 'Original (Kopie)')->first();
        $this->assertNotNull($copy);
        $this->assertSame('body { color: red; }', $copy->css_content);
    }

    public function test_deleting_a_template_removes_its_header_image(): void
    {
        Storage::fake('public');
        $user = User::factory()->create();
        $group = $user->groups()->first();
        $path = UploadedFile::fake()->create('logo.png', 10, 'image/png')->store('billing-template-images', 'public');
        $template = BillingTemplate::create(['user_group_id' => $group->id, 'name' => 'Test', 'content' => 'Inhalt', 'header_image_path' => $path]);

        $response = $this->actingAs($user)->delete(route('billing-templates.destroy', $template));

        $response->assertRedirect(route('billing-templates.index'));
        $this->assertNull(BillingTemplate::find($template->id));
        Storage::disk('public')->assertMissing($path);
    }

    /**
     * The preview button on the edit page sends the form's *current* (possibly
     * unsaved) header/content/footer, not the persisted template – so a user can
     * iterate on their template's Blade syntax without saving after every change.
     */
    public function test_the_preview_action_renders_the_submitted_unsaved_content_with_fake_data(): void
    {
        $user = User::factory()->create();
        $group = $user->groups()->first();
        $template = BillingTemplate::create([
            'user_group_id' => $group->id, 'name' => 'Test', 'content' => 'Gespeicherter Inhalt',
        ]);

        $response = $this->actingAs($user)->post(route('billing-templates.preview', $template), [
            'content' => '<p>{{ $managementGroup->description }}</p><p>{{ euro($costsByCategory["Heizkosten"]) }}</p>',
            'header' => '<p>Kopf: {{ $managementUnit->street }}</p>',
            'footer' => '<p>Fuß: {{ $billingInterval->start_date->format("d.m.Y") }}</p>',
        ]);

        $response->assertOk();
        $response->assertSee('Musterverwaltung GbR');
        $response->assertSee('840,00', false);
        $response->assertSee('Musterstraße 1');
        $response->assertSee('01.01.2026');
        // Der gespeicherte Inhalt wird nicht verwendet, solange abweichende Werte
        // übermittelt werden.
        $response->assertDontSee('Gespeicherter Inhalt');

        // Reine Vorschau: das Template bleibt unverändert in der Datenbank.
        $this->assertSame('Gespeicherter Inhalt', $template->fresh()->content);
    }

    /**
     * Konzept.md "### Template": "Beim Erstellen der Betriebskostenabrechnung muss
     * dann der Content und die Formatierung zusammen gebracht werden."
     */
    public function test_the_preview_action_merges_the_submitted_css_content_into_the_head(): void
    {
        $user = User::factory()->create();
        $group = $user->groups()->first();
        $template = BillingTemplate::create([
            'user_group_id' => $group->id, 'name' => 'Test', 'content' => 'Inhalt',
        ]);

        $response = $this->actingAs($user)->post(route('billing-templates.preview', $template), [
            'content' => '<html><head></head><body>Inhalt</body></html>',
            'css_content' => 'body { color: red; }',
        ]);

        $response->assertOk();
        $response->assertSee('<style>body { color: red; }</style>', false);
    }

    public function test_the_preview_action_reuses_the_templates_own_saved_header_image(): void
    {
        Storage::fake('public');
        $user = User::factory()->create();
        $group = $user->groups()->first();
        $imagePath = 'billing-template-images/logo.png';
        Storage::disk('public')->put($imagePath, 'PNG-Daten');
        $template = BillingTemplate::create([
            'user_group_id' => $group->id, 'name' => 'Test', 'content' => 'Inhalt', 'header_image_path' => $imagePath,
        ]);

        $response = $this->actingAs($user)->post(route('billing-templates.preview', $template), [
            'content' => '@if ($headerImage)<p>Bild vorhanden</p>@endif',
        ]);

        $response->assertOk();
        $response->assertSee('Bild vorhanden');
    }

    public function test_a_user_cannot_preview_a_template_belonging_to_a_group_they_are_not_a_member_of(): void
    {
        $user = User::factory()->create();
        $owner = User::factory()->create();
        $foreignGroup = $owner->groups()->first();
        $template = BillingTemplate::create(['user_group_id' => $foreignGroup->id, 'name' => 'Fremd', 'content' => 'Inhalt']);

        $response = $this->actingAs($user)->post(route('billing-templates.preview', $template), [
            'content' => 'Inhalt',
        ]);

        $response->assertForbidden();
    }

    public function test_the_check_action_accepts_a_syntactically_valid_template_using_only_known_variables(): void
    {
        $user = User::factory()->create();
        $group = $user->groups()->first();
        $template = BillingTemplate::create(['user_group_id' => $group->id, 'name' => 'Test', 'content' => 'Alt']);

        $response = $this->actingAs($user)->post(route('billing-templates.check', $template), [
            'content' => '<p>{{ $managementUnit->street }}</p>@foreach ($leases as $entry)<p>{{ $entry["tenantNames"] }}</p>@endforeach',
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('status');
        $response->assertSessionDoesntHaveErrors('template_check');
    }

    /**
     * Regression: the known-properties whitelist used to be a manually maintained
     * list per model, which had silently drifted out of sync with the real Lease
     * schema (missing `is_pre_contract`, added later). It's now derived straight from
     * the database schema, so every real column is recognized without needing a
     * matching update here.
     */
    public function test_the_check_action_accepts_a_lease_property_that_only_exists_in_the_database_schema(): void
    {
        $user = User::factory()->create();
        $group = $user->groups()->first();
        $template = BillingTemplate::create(['user_group_id' => $group->id, 'name' => 'Test', 'content' => 'Alt']);

        $response = $this->actingAs($user)->post(route('billing-templates.check', $template), [
            'content' => '@foreach ($leases as $entry)<p>{{ $entry["lease"]->is_pre_contract }}</p>@endforeach',
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('status');
        $response->assertSessionDoesntHaveErrors('template_check');
    }

    /**
     * Regression ("Unbekannte Eigenschaft \"fullNam\" (Modell \"landlord\")"): PCRE's
     * greedy "\w+" backtracked past the "e" of "fullName" to dodge the negative
     * lookahead that's supposed to exclude method calls, since "fullNam" (unlike
     * "fullName") isn't immediately followed by "(". A possessive "\w++" fixes this.
     */
    public function test_the_check_action_accepts_a_method_call_whose_name_ends_the_same_as_a_shorter_substring(): void
    {
        $user = User::factory()->create();
        $group = $user->groups()->first();
        $template = BillingTemplate::create(['user_group_id' => $group->id, 'name' => 'Test', 'content' => 'Alt']);

        $response = $this->actingAs($user)->post(route('billing-templates.check', $template), [
            'content' => '<p>{{ $landlord?->fullName() }}</p>',
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('status');
        $response->assertSessionDoesntHaveErrors('template_check');
    }

    public function test_the_check_action_reports_a_blade_syntax_error(): void
    {
        $user = User::factory()->create();
        $group = $user->groups()->first();
        $template = BillingTemplate::create(['user_group_id' => $group->id, 'name' => 'Test', 'content' => 'Alt']);

        $response = $this->actingAs($user)->post(route('billing-templates.check', $template), [
            // Fehlendes @endforeach.
            'content' => '@foreach ($leases as $entry) {{ $entry["tenantNames"] }}',
        ]);

        $response->assertRedirect();
        $response->assertSessionHasErrors('template_check');
        $response->assertSessionMissing('status');
    }

    public function test_the_check_action_reports_an_unknown_variable_by_name(): void
    {
        $user = User::factory()->create();
        $group = $user->groups()->first();
        $template = BillingTemplate::create(['user_group_id' => $group->id, 'name' => 'Test', 'content' => 'Alt']);

        $response = $this->actingAs($user)->post(route('billing-templates.check', $template), [
            'content' => '<p>{{ $mangementUnit->street }}</p>',
        ]);

        $response->assertRedirect();
        $message = session('errors')['default']['messages']['template_check'][0];
        $this->assertStringContainsString('$mangementUnit', $message);
    }

    public function test_the_check_action_reports_an_unknown_array_key_by_name(): void
    {
        $user = User::factory()->create();
        $group = $user->groups()->first();
        $template = BillingTemplate::create(['user_group_id' => $group->id, 'name' => 'Test', 'content' => 'Alt']);

        $response = $this->actingAs($user)->post(route('billing-templates.check', $template), [
            'content' => '@foreach ($leases as $entry) {{ $entry["tenantName"] }} @endforeach',
        ]);

        $response->assertRedirect();
        $message = session('errors')['default']['messages']['template_check'][0];
        $this->assertStringContainsString('tenantName', $message);
    }

    /**
     * Regression: a typo'd variable inside a branch the fake test data happens not to
     * enter (e.g. an @if that evaluates false, or a second loop iteration that never
     * happens because the fake data only has one lease) used to slip through
     * completely, because the dynamic "render with fake data" check only ever
     * executes the code paths the fake data actually takes. The static scan below
     * catches the variable name regardless of which branch it's in.
     */
    public function test_the_check_action_reports_an_unknown_variable_hidden_inside_a_branch_the_fake_data_never_enters(): void
    {
        $user = User::factory()->create();
        $group = $user->groups()->first();
        $template = BillingTemplate::create(['user_group_id' => $group->id, 'name' => 'Test', 'content' => 'Alt']);

        $response = $this->actingAs($user)->post(route('billing-templates.check', $template), [
            // $headerImage is null in the fake preview data, so this branch never runs
            // during a dynamic render – yet the typo must still be reported.
            'content' => '@if ($headerImage) {{ $managementGrop->description }} @endif<p>{{ $managementGroup->description }}</p>',
        ]);

        $response->assertRedirect();
        $message = session('errors')['default']['messages']['template_check'][0];
        $this->assertStringContainsString('$managementGrop', $message);
    }

    public function test_the_check_action_accepts_a_template_using_php_directives_to_remember_a_running_total(): void
    {
        $user = User::factory()->create();
        $group = $user->groups()->first();
        $template = BillingTemplate::create(['user_group_id' => $group->id, 'name' => 'Test', 'content' => 'Alt']);

        $response = $this->actingAs($user)->post(route('billing-templates.check', $template), [
            'content' => '@php($summe = 0)@foreach ($costsByCategory as $kategorie => $betrag)@php($summe += $betrag){{ $kategorie }}: {{ euro($betrag) }}@endforeach Gesamt: {{ euro($summe) }}',
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('status');
        $response->assertSessionDoesntHaveErrors('template_check');
    }

    /**
     * Regression: Eloquent's magic __get() silently returns null for an unknown
     * attribute – no PHP warning at all – so a typo'd property name (missing the "n")
     * would otherwise slip through both the static "unknown variable" scan (the
     * variable $managementGroup itself IS known) and the dynamic render (no warning
     * is ever raised to convert into an exception).
     */
    public function test_the_check_action_reports_an_unknown_property_on_a_known_model_variable(): void
    {
        $user = User::factory()->create();
        $group = $user->groups()->first();
        $template = BillingTemplate::create(['user_group_id' => $group->id, 'name' => 'Test', 'content' => 'Alt']);

        $response = $this->actingAs($user)->post(route('billing-templates.check', $template), [
            'content' => '<p>{{ $managementGroup->descriptio }}</p>',
        ]);

        $response->assertRedirect();
        $message = session('errors')['default']['messages']['template_check'][0];
        $this->assertStringContainsString('descriptio', $message);
    }

    /**
     * Regression: a malformed/mistyped closing tag like "</htm>" instead of "</html>"
     * doesn't raise any PHP warning or Blade compile error either – it's syntactically
     * valid Blade/PHP, just structurally broken HTML. A browser silently repairs it,
     * but DomPDF's stricter parser can garble or break the PDF export.
     */
    public function test_the_check_action_reports_a_malformed_closing_tag(): void
    {
        $user = User::factory()->create();
        $group = $user->groups()->first();
        $template = BillingTemplate::create(['user_group_id' => $group->id, 'name' => 'Test', 'content' => 'Alt']);

        $response = $this->actingAs($user)->post(route('billing-templates.check', $template), [
            'content' => '<html><body><p>Test</p></body></htm>',
        ]);

        $response->assertRedirect();
        $message = session('errors')['default']['messages']['template_check'][0];
        $this->assertStringContainsString('htm', $message);
    }

    /**
     * A bare "&" typed directly as plain text (e.g. a company name like "Müller &
     * Sohn") is common, legitimate content and must not be flagged as an HTML error –
     * only genuine tag-structure problems should be.
     */
    public function test_the_check_action_does_not_flag_a_literal_ampersand_as_an_html_error(): void
    {
        $user = User::factory()->create();
        $group = $user->groups()->first();
        $template = BillingTemplate::create(['user_group_id' => $group->id, 'name' => 'Test', 'content' => 'Alt']);

        $response = $this->actingAs($user)->post(route('billing-templates.check', $template), [
            'content' => '<p>Müller & Sohn GmbH</p>',
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('status');
        $response->assertSessionDoesntHaveErrors('template_check');
    }

    public function test_a_user_cannot_check_a_template_belonging_to_a_group_they_are_not_a_member_of(): void
    {
        $user = User::factory()->create();
        $owner = User::factory()->create();
        $foreignGroup = $owner->groups()->first();
        $template = BillingTemplate::create(['user_group_id' => $foreignGroup->id, 'name' => 'Fremd', 'content' => 'Inhalt']);

        $response = $this->actingAs($user)->post(route('billing-templates.check', $template), [
            'content' => 'Inhalt',
        ]);

        $response->assertForbidden();
    }

    public function test_guests_cannot_use_the_check_action(): void
    {
        $template = BillingTemplate::create(['name' => 'Intern', 'content' => 'Inhalt']);

        $response = $this->post(route('billing-templates.check', $template), ['content' => 'Inhalt']);

        $response->assertRedirect('/login');
    }

    public function test_the_index_lists_internal_and_own_group_templates_but_not_other_groups(): void
    {
        $user = User::factory()->create();
        $group = $user->groups()->first();
        $outsider = User::factory()->create();
        $foreignGroup = $outsider->groups()->first();

        BillingTemplate::create(['name' => 'Intern', 'content' => 'Inhalt']);
        BillingTemplate::create(['user_group_id' => $group->id, 'name' => 'Eigenes', 'content' => 'Inhalt']);
        BillingTemplate::create(['user_group_id' => $foreignGroup->id, 'name' => 'Fremdes', 'content' => 'Inhalt']);

        $response = $this->actingAs($user)->get(route('billing-templates.index'));

        $response->assertOk();
        $response->assertSee('Intern');
        $response->assertSee('Eigenes');
        $response->assertSee($group->name);
        $response->assertDontSee('Fremdes');
    }

    private function checkTemplate(string $content): TestResponse
    {
        $user = User::factory()->create();
        $template = BillingTemplate::create(['user_group_id' => $user->groups()->first()->id, 'name' => 'Test', 'content' => 'Alt']);

        return $this->actingAs($user)->post(route('billing-templates.check', $template), ['content' => $content]);
    }

    // KI-generiert
    public function test_the_check_action_accepts_mpdf_and_html5_tags(): void
    {
        $this->checkTemplate(
            '<html><body><section><header>Kopf</header>'
            .'@foreach ($leases as $entry) @if (! $loop->first) <pagebreak /> @endif <p>Brief</p> @endforeach'
            .'<columnbreak /><dottab /></section></body></html>'
        )
            ->assertSessionHas('status')
            ->assertSessionDoesntHaveErrors('template_check');
    }

    // KI-generiert
    public function test_the_check_action_still_reports_a_mistyped_tag(): void
    {
        $this->checkTemplate('<html><body><p>Brief</p><pagebrek /></body></html>')
            ->assertSessionHasErrors('template_check')
            ->assertSessionMissing('status');

        $this->assertStringContainsString('pagebrek', session('errors')->first('template_check'));
    }

    // KI-generiert
    public function test_the_check_action_rejects_mpdfs_abstract_base_tag_names(): void
    {
        // "Tag" und "BlockTag" sind in mPDF nur Basisklassen, keine darstellbaren Tags.
        $this->checkTemplate('<html><body><blocktag>x</blocktag></body></html>')
            ->assertSessionHasErrors('template_check');
    }

    // KI-generiert
    public function test_the_default_template_passes_the_check(): void
    {
        $this->seed(BillingTemplateSeeder::class);
        $default = BillingTemplate::where('is_default', true)->sole();

        $user = User::factory()->create();
        $template = BillingTemplate::create(['user_group_id' => $user->groups()->first()->id, 'name' => 'Kopie', 'content' => 'Alt']);

        $this->actingAs($user)->post(route('billing-templates.check', $template), [
            'content' => $default->content,
            'css_content' => $default->css_content,
        ])
            ->assertSessionHas('status')
            ->assertSessionDoesntHaveErrors('template_check');
    }
}
