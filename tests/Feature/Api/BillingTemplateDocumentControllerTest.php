<?php

namespace Tests\Feature\Api;

use App\Models\BillingTemplate;
use App\Models\LeaseDocument;
use App\Models\User;
use App\Support\FileStorage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Api\Concerns\CreatesApiFixtures;
use Tests\TestCase;

// KI-generiert
class BillingTemplateDocumentControllerTest extends TestCase
{
    use CreatesApiFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake(FileStorage::diskName());
    }

    /**
     * @return array<string, mixed>
     */
    private function templatePayload(User $user, array $overrides = []): array
    {
        return ['user_group_id' => $this->groupOf($user)->id, 'name' => 'Eigene', 'content' => '<p>x</p>', ...$overrides];
    }

    public function test_a_template_with_header_image_can_be_created_and_is_never_the_default(): void
    {
        $user = $this->apiUser();

        $template = $this->post(route('api.billing-templates.store'), $this->templatePayload($user, [
            'is_default' => true, 'header_image' => UploadedFile::fake()->image('kopf.png'),
        ]), ['Accept' => 'application/json'])->assertCreated()->assertJsonPath('is_default', false)->json();

        FileStorage::disk()->assertExists($template['header_image_path']);
        $this->getJson(route('api.billing-templates.index'))->assertOk()->assertJsonFragment(['name' => 'Eigene']);
        $this->getJson(route('api.billing-templates.show', $template['id']))->assertOk();
    }

    public function test_a_template_can_be_updated_its_image_replaced_removed_and_deleted(): void
    {
        $user = $this->apiUser();
        $id = $this->postJson(route('api.billing-templates.store'), $this->templatePayload($user))->assertCreated()->json('id');

        $path = $this->post(route('api.billing-templates.header-image.store', $id), [
            'header_image' => UploadedFile::fake()->image('neu.png'),
        ], ['Accept' => 'application/json'])->assertOk()->json('header_image_path');
        FileStorage::disk()->assertExists($path);

        $this->putJson(route('api.billing-templates.update', $id), $this->templatePayload($user, ['name' => 'Umbenannt', 'remove_header_image' => true]))
            ->assertOk()->assertJsonPath('name', 'Umbenannt')->assertJsonPath('header_image_path', null);
        FileStorage::disk()->assertMissing($path);

        $this->postJson(route('api.billing-templates.header-image.store', $id), [])->assertUnprocessable();
        $this->deleteJson(route('api.billing-templates.destroy', $id))->assertNoContent();
        $this->assertNull(BillingTemplate::find($id));
    }

    public function test_global_and_foreign_templates_cannot_be_changed(): void
    {
        $global = BillingTemplate::create(['name' => 'Standard', 'content' => 'x']);
        $foreign = BillingTemplate::create(['name' => 'Fremd', 'content' => 'x', 'user_group_id' => $this->groupOf(User::factory()->create())->id]);
        $user = $this->apiUser();

        $this->getJson(route('api.billing-templates.show', $global))->assertOk();
        $this->getJson(route('api.billing-templates.show', $foreign))->assertForbidden();
        $this->putJson(route('api.billing-templates.update', $global), $this->templatePayload($user))->assertForbidden();
        $this->post(route('api.billing-templates.header-image.store', $foreign), [
            'header_image' => UploadedFile::fake()->image('x.png'),
        ], ['Accept' => 'application/json'])->assertForbidden();
        $this->deleteJson(route('api.billing-templates.destroy', $foreign))->assertForbidden();
    }

    public function test_lease_documents_can_be_uploaded_and_deleted(): void
    {
        $lease = $this->makeLease($this->apiUser());

        $document = $this->post(route('api.leases.documents.store', $lease), [
            'file' => UploadedFile::fake()->create('vertrag.pdf', 10, 'application/pdf'),
        ], ['Accept' => 'application/json'])->assertCreated()->assertJsonPath('original_name', 'vertrag.pdf')->json();
        FileStorage::disk()->assertExists($document['file_path']);

        $this->deleteJson(route('api.lease-documents.destroy', $document['id']))->assertNoContent();
        FileStorage::disk()->assertMissing($document['file_path']);
        $this->assertNull(LeaseDocument::find($document['id']));
    }

    public function test_lease_documents_need_a_file_and_access_to_the_lease(): void
    {
        $own = $this->makeLease($this->apiUser());
        $this->postJson(route('api.leases.documents.store', $own), [])->assertUnprocessable()->assertJsonValidationErrors('file');

        $foreign = $this->makeLease(User::factory()->create());
        $document = $foreign->documents()->create(['file_path' => 'lease-documents/x.pdf', 'original_name' => 'x.pdf']);
        $this->post(route('api.leases.documents.store', $foreign), [
            'file' => UploadedFile::fake()->create('v.pdf', 10, 'application/pdf'),
        ], ['Accept' => 'application/json'])->assertForbidden();
        $this->deleteJson(route('api.lease-documents.destroy', $document))->assertForbidden();
    }
}
