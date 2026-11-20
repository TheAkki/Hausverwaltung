<?php

// KI-generiert

namespace Tests\Feature;

use App\Http\Controllers\FileDownloadController;
use App\Models\AccountTransfer;
use App\Models\BillingTemplate;
use App\Models\Invoice;
use App\Models\SettlementClaim;
use App\Models\User;
use App\Support\FileStorage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Api\Concerns\CreatesApiFixtures;
use Tests\TestCase;

/**
 * Hochgeladene Dateien liegen auf einer nicht öffentlichen Disk und kommen nur über
 * FileDownloadController – an Benutzer, die den Datensatz sehen dürfen.
 */
class ProtectedFilesTest extends TestCase
{
    use CreatesApiFixtures, RefreshDatabase;

    private User $owner;

    private User $stranger;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake(FileStorage::diskName());
        $this->owner = User::factory()->create();
        $this->stranger = User::factory()->create();
    }

    private function stored(string $path): string
    {
        FileStorage::disk()->put($path, 'INHALT');

        return $path;
    }

    private function invoice(?string $path): Invoice
    {
        $managementGroup = $this->makeManagementGroup($this->owner);

        return Invoice::create([
            'invoice_issuer_id' => $this->makeIssuer($this->owner)->id, 'amount' => 10, 'invoice_date' => '2026-01-01', 'periodicity' => 'once',
            'costable_type' => $managementGroup->getMorphClass(), 'costable_id' => $managementGroup->id,
            'file_path' => $path, 'file_original_name' => 'Beleg.pdf',
        ]);
    }

    public function test_the_files_disk_is_not_the_public_one(): void
    {
        $this->assertNotSame('public', FileStorage::diskName());
        $this->assertNotSame(config('files.legacy_disk'), FileStorage::diskName());
    }

    public function test_an_invoice_receipt_is_downloaded_or_shown_only_to_members(): void
    {
        $invoice = $this->invoice($this->stored('invoices/a.pdf'));

        $download = $this->actingAs($this->owner)->get(route('files.invoice', $invoice))->assertOk();
        $this->assertStringContainsString('attachment', (string) $download->headers->get('Content-Disposition'));
        $this->assertStringContainsString('Beleg.pdf', (string) $download->headers->get('Content-Disposition'));
        $this->assertSame('INHALT', $download->streamedContent());

        $inline = $this->actingAs($this->owner)->get(route('files.invoice', [$invoice, FileDownloadController::INLINE => 1]))->assertOk();
        $this->assertStringContainsString('inline', (string) $inline->headers->get('Content-Disposition'));

        $this->actingAs($this->stranger)->get(route('files.invoice', $invoice))->assertForbidden();

        $this->app['auth']->forgetGuards();
        $this->get(route('files.invoice', $invoice))->assertRedirect(route('login'));
    }

    public function test_a_missing_file_is_not_found(): void
    {
        $this->actingAs($this->owner)->get(route('files.invoice', $this->invoice(null)))->assertNotFound();
        $this->actingAs($this->owner)->get(route('files.invoice', $this->invoice('invoices/weg.pdf')))->assertNotFound();
    }

    public function test_lease_documents_claims_and_transfers_are_protected(): void
    {
        $lease = $this->makeLease($this->owner);
        $document = $lease->documents()->create(['file_path' => $this->stored('lease-documents/v.pdf'), 'original_name' => 'Vertrag.pdf']);
        $claim = SettlementClaim::create([
            'lease_id' => $lease->id, 'billing_interval_id' => $lease->rentalUnit->managementUnit->managementGroup->billingIntervals()->value('id'),
            'amount' => 10, 'date' => '2026-02-01', 'file_path' => $this->stored('settlement-claims/f.pdf'), 'file_original_name' => 'Abrechnung.pdf',
        ]);
        $managementGroup = $this->makeManagementGroup($this->owner, kontosystem: true);
        $from = $managementGroup->accounts()->create(['name' => 'A']);
        $to = $managementGroup->accounts()->create(['name' => 'B']);
        $transfer = AccountTransfer::create([
            'from_account_id' => $from->id, 'to_account_id' => $to->id, 'amount' => 5, 'transfer_date' => '2026-02-01',
            'file_path' => $this->stored('account-transfers/u.pdf'), 'file_original_name' => 'Umbuchung.pdf',
        ]);

        foreach ([route('files.lease-document', $document), route('files.settlement-claim', $claim), route('files.account-transfer', $transfer)] as $url) {
            $this->actingAs($this->owner)->get($url)->assertOk();
            $this->actingAs($this->owner)->get($url.'?'.FileDownloadController::INLINE.'=1')->assertOk();
            $this->actingAs($this->stranger)->get($url)->assertForbidden();
        }
    }

    public function test_a_header_image_is_shown_to_members_and_for_builtin_templates_to_everyone(): void
    {
        $own = BillingTemplate::create(['user_group_id' => $this->groupOf($this->owner)->id, 'name' => 'Eigen', 'content' => 'x', 'header_image_path' => $this->stored('billing-template-images/e.png')]);
        $builtin = BillingTemplate::create(['user_group_id' => null, 'name' => 'Eingebaut', 'content' => 'x', 'header_image_path' => $this->stored('billing-template-images/s.png')]);

        $this->assertSame(route('files.billing-template-image', $own), $own->headerImageUrl());
        $this->actingAs($this->owner)->get($own->headerImageUrl())->assertOk();
        $this->actingAs($this->stranger)->get($own->headerImageUrl())->assertForbidden();
        $this->actingAs($this->stranger)->get($builtin->headerImageUrl())->assertOk();
        $this->assertNull(BillingTemplate::create(['name' => 'Ohne', 'content' => 'x'])->headerImageUrl());
    }
}
