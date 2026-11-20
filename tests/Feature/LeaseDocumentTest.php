<?php

namespace Tests\Feature;

use App\Models\Lease;
use App\Models\ManagementGroup;
use App\Models\Tenant;
use App\Models\User;
use App\Support\FileStorage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class LeaseDocumentTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_document_can_be_uploaded_to_and_removed_from_a_lease(): void
    {
        Storage::fake(FileStorage::diskName());

        $user = User::factory()->create();
        $group = $user->groups()->first();

        $managementGroup = ManagementGroup::create(['description' => 'MG', 'user_group_id' => $group->id]);
        $managementUnit = $managementGroup->managementUnits()->create([
            'street' => 'Teststr 1', 'postal_code' => '11111', 'city' => 'Teststadt',
        ]);
        $rentalUnit = $managementUnit->rentalUnits()->create([
            'type' => 'Wohnung', 'size' => 50, 'is_relevant_for_billing' => true,
        ]);
        $tenant = Tenant::create(['first_name' => 'Max', 'last_name' => 'Mustermann', 'street' => 'A', 'postal_code' => '1', 'city' => 'B']);
        $lease = Lease::create([
            'rental_unit_id' => $rentalUnit->id, 'start_date' => '2026-01-01',
            'rent_adjustment_type' => 'fixed', 'cold_rent' => 500,
        ]);
        $lease->tenants()->attach($tenant->id);

        $file = UploadedFile::fake()->create('mietvertrag.pdf', 100, 'application/pdf');

        $response = $this->actingAs($user)->post(route('leases.documents.store', $lease), ['file' => $file]);

        $response->assertRedirect(route('leases.show', $lease));
        $this->assertCount(1, $lease->fresh()->documents);

        $document = $lease->fresh()->documents->first();
        $this->assertSame('mietvertrag.pdf', $document->original_name);
        FileStorage::disk()->assertExists($document->file_path);

        $deleteResponse = $this->actingAs($user)->delete(route('lease-documents.destroy', $document));

        $deleteResponse->assertRedirect(route('leases.show', $lease));
        $this->assertCount(0, $lease->fresh()->documents);
        FileStorage::disk()->assertMissing($document->file_path);
    }
}
