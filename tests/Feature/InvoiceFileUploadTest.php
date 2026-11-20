<?php

namespace Tests\Feature;

use App\Models\CostCategory;
use App\Models\Invoice;
use App\Models\InvoiceIssuer;
use App\Models\ManagementGroup;
use App\Models\User;
use App\Support\FileStorage;
use Database\Seeders\CostCategorySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class InvoiceFileUploadTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_file_can_be_attached_when_creating_an_invoice(): void
    {
        Storage::fake(FileStorage::diskName());
        $this->seed(CostCategorySeeder::class);

        $user = User::factory()->create();
        $group = $user->groups()->first();

        $managementGroup = ManagementGroup::create(['description' => 'MG', 'user_group_id' => $group->id]);
        $managementUnit = $managementGroup->managementUnits()->create([
            'street' => 'Teststr 1', 'postal_code' => '11111', 'city' => 'Teststadt',
        ]);
        $billingInterval = $managementGroup->billingIntervals()->first();
        $issuer = InvoiceIssuer::create(['user_group_id' => $group->id, 'name' => 'Stadtwerke', 'street' => 'A', 'postal_code' => '1', 'city' => 'B']);
        $category = CostCategory::first();

        $file = UploadedFile::fake()->create('rechnung.pdf', 50, 'application/pdf');

        $response = $this->actingAs($user)->post('/invoices', [
            'invoice_issuer_id' => $issuer->id,
            'invoice_date' => '2026-06-15',
            'amount' => 150,
            'periodicity' => 'once',
            'costable' => "management_unit:{$managementUnit->id}",
            'billing_interval_id' => $billingInterval->id,
            'cost_category_id' => $category->id,
            'file' => $file,
        ]);

        $invoice = Invoice::first();
        $response->assertRedirect(route('invoices.show', $invoice));

        $this->assertNotNull($invoice->file_path);
        FileStorage::disk()->assertExists($invoice->file_path);
    }

    public function test_uploading_a_replacement_file_deletes_the_old_one(): void
    {
        Storage::fake(FileStorage::diskName());

        $user = User::factory()->create();
        $group = $user->groups()->first();
        $managementGroup = ManagementGroup::create(['description' => 'Replace Group', 'user_group_id' => $group->id]);
        $issuer = InvoiceIssuer::create(['user_group_id' => $group->id, 'name' => 'Stadtwerke', 'street' => 'A', 'postal_code' => '1', 'city' => 'B']);

        $oldPath = UploadedFile::fake()->create('old.pdf', 10)->store('invoices', FileStorage::diskName());

        $invoice = Invoice::create([
            'invoice_issuer_id' => $issuer->id,
            'costable_type' => $managementGroup->getMorphClass(),
            'costable_id' => $managementGroup->id,
            'invoice_date' => '2026-06-15',
            'amount' => 150,
            'periodicity' => 'once',
            'file_path' => $oldPath,
        ]);

        $newFile = UploadedFile::fake()->create('new.pdf', 10, 'application/pdf');

        $this->actingAs($user)->put(route('invoices.update', $invoice), [
            'invoice_issuer_id' => $issuer->id,
            'invoice_date' => '2026-06-15',
            'amount' => 150,
            'file' => $newFile,
        ]);

        FileStorage::disk()->assertMissing($oldPath);
        FileStorage::disk()->assertExists($invoice->fresh()->file_path);
        $this->assertNotSame($oldPath, $invoice->fresh()->file_path);
    }
}
