<?php

// KI-generiert

namespace Tests\Feature;

use App\Models\Account;
use App\Models\AccountTransfer;
use App\Models\Lease;
use App\Models\ManagementGroup;
use App\Models\SettlementClaim;
use App\Models\User;
use App\Services\BackupService;
use App\Services\RecordReceipt;
use App\Support\FileStorage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Api\Concerns\CreatesApiFixtures;
use Tests\TestCase;

/**
 * Belege für Forderungen und Umbuchungen (Konzept.md: "Zu allen Ein- und Ausgaben
 * sollen Rechnungen hinterlegt werden können") – Web, API und Backup.
 */
class RecordReceiptTest extends TestCase
{
    use CreatesApiFixtures, RefreshDatabase;

    private User $user;

    private Lease $lease;

    private ManagementGroup $managementGroup;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake(FileStorage::diskName());
        $this->user = User::factory()->create();
        $this->managementGroup = $this->makeManagementGroup($this->user, kontosystem: true);
        $this->lease = $this->makeLease($this->user, $this->makeRentalUnit($this->user, managementUnit: $this->makeManagementUnit($this->user, $this->managementGroup)));
    }

    private function pdf(string $name = 'beleg.pdf'): UploadedFile
    {
        return UploadedFile::fake()->create($name, 10, 'application/pdf');
    }

    /**
     * @return array<string, mixed>
     */
    private function claimInput(array $extra = []): array
    {
        return ['billing_interval_id' => $this->managementGroup->billingIntervals()->value('id'), 'date' => '2026-03-01', 'amount' => 120, ...$extra];
    }

    /**
     * @return array{0: Account, 1: Account}
     */
    private function accounts(): array
    {
        return [$this->managementGroup->accounts()->create(['name' => 'Giro']), $this->managementGroup->accounts()->create(['name' => 'Rücklage'])];
    }

    public function test_a_claim_receipt_is_uploaded_replaced_removed_and_deleted_with_the_claim(): void
    {
        $this->actingAs($this->user)->post(route('settlement-claims.store'), $this->claimInput(['lease_id' => $this->lease->id, RecordReceipt::FIELD_FILE => $this->pdf('abrechnung.pdf')]))
            ->assertSessionHasNoErrors();
        $claim = SettlementClaim::sole();
        $first = $claim->file_path;
        FileStorage::disk()->assertExists($first);
        $this->assertSame('abrechnung.pdf', $claim->file_original_name);

        $this->actingAs($this->user)->get(route('leases.show', $this->lease))->assertOk()->assertSee(route('files.settlement-claim', $claim), false);
        $this->actingAs($this->user)->get(route('settlement-claims.edit', $claim))->assertOk()->assertSee('data-receipt-current', false);

        $this->actingAs($this->user)->put(route('settlement-claims.update', $claim), $this->claimInput([RecordReceipt::FIELD_FILE => $this->pdf('neu.pdf')]));
        FileStorage::disk()->assertMissing($first);
        $this->assertSame('neu.pdf', $claim->fresh()->file_original_name);

        $this->actingAs($this->user)->put(route('settlement-claims.update', $claim), $this->claimInput([RecordReceipt::FIELD_REMOVE => 1]));
        $this->assertNull($claim->fresh()->file_path);
        $this->assertSame([], FileStorage::disk()->allFiles());

        $this->actingAs($this->user)->put(route('settlement-claims.update', $claim), $this->claimInput([RecordReceipt::FIELD_FILE => $this->pdf()]));
        $path = $claim->fresh()->file_path;
        $this->actingAs($this->user)->delete(route('settlement-claims.destroy', $claim));
        FileStorage::disk()->assertMissing($path);
    }

    public function test_a_claim_rejects_a_disallowed_file_type(): void
    {
        $this->actingAs($this->user)->post(route('settlement-claims.store'), $this->claimInput(['lease_id' => $this->lease->id, RecordReceipt::FIELD_FILE => UploadedFile::fake()->create('x.exe', 1)]))
            ->assertSessionHasErrors(RecordReceipt::FIELD_FILE);
        $this->assertSame(0, SettlementClaim::count());
    }

    public function test_a_transfer_receipt_is_uploaded_with_the_transfer_or_later(): void
    {
        [$from, $to] = $this->accounts();
        $input = ['management_group_id' => $this->managementGroup->id, 'from_account_id' => $from->id, 'to_account_id' => $to->id, 'amount' => 50, 'transfer_date' => '2026-03-01'];

        $this->actingAs($this->user)->post(route('account-transfers.store'), [...$input, RecordReceipt::FIELD_FILE => $this->pdf('umbuchung.pdf')])
            ->assertRedirect(route('management-groups.show', $this->managementGroup));
        $withFile = AccountTransfer::sole();
        $this->assertSame('umbuchung.pdf', $withFile->file_original_name);

        $this->actingAs($this->user)->post(route('account-transfers.store'), $input);
        $later = AccountTransfer::whereNull('file_path')->sole();
        $this->actingAs($this->user)->get(route('management-groups.show', $this->managementGroup))
            ->assertOk()->assertSee('data-transfers', false)
            ->assertSee(route('files.account-transfer', $withFile), false)
            ->assertSee(route('account-transfers.file.store', $later), false);

        $this->actingAs($this->user)->post(route('account-transfers.file.store', $later), [RecordReceipt::FIELD_FILE => $this->pdf('nachgereicht.pdf')]);
        $this->assertSame('nachgereicht.pdf', $later->fresh()->file_original_name);

        $this->actingAs($this->user)->delete(route('account-transfers.file.destroy', $later));
        $this->assertNull($later->fresh()->file_path);

        $stranger = User::factory()->create();
        $this->actingAs($stranger)->post(route('account-transfers.file.store', $later), [RecordReceipt::FIELD_FILE => $this->pdf()])->assertForbidden();
        $this->actingAs($stranger)->delete(route('account-transfers.file.destroy', $withFile))->assertForbidden();
    }

    public function test_the_api_uploads_downloads_and_removes_receipts(): void
    {
        [$from, $to] = $this->accounts();
        $transfer = AccountTransfer::create(['from_account_id' => $from->id, 'to_account_id' => $to->id, 'amount' => 5, 'transfer_date' => '2026-03-01']);
        $claim = SettlementClaim::create(['lease_id' => $this->lease->id, ...$this->claimInput()]);
        Sanctum::actingAs($this->user);

        foreach (["/api/settlement-claims/{$claim->id}/file", "/api/account-transfers/{$transfer->id}/file"] as $url) {
            $this->post($url, [], ['Accept' => 'application/json'])->assertJsonValidationErrors(RecordReceipt::FIELD_FILE);
            $this->post($url, [RecordReceipt::FIELD_FILE => $this->pdf('api.pdf')], ['Accept' => 'application/json'])
                ->assertOk()->assertJsonPath('file_original_name', 'api.pdf');
            $this->get($url)->assertOk()->assertDownload('api.pdf');
            $this->deleteJson($url)->assertOk()->assertJsonPath('file_path', null);
            $this->get($url)->assertNotFound();
        }

        $this->post("/api/settlement-claims/{$claim->id}/file", [RecordReceipt::FIELD_FILE => $this->pdf()], ['Accept' => 'application/json']);
        $path = $claim->fresh()->file_path;
        $this->deleteJson("/api/settlement-claims/{$claim->id}")->assertNoContent();
        FileStorage::disk()->assertMissing($path);

        Sanctum::actingAs(User::factory()->create());
        $this->getJson("/api/account-transfers/{$transfer->id}/file")->assertForbidden();
    }

    public function test_a_backup_keeps_the_receipts_of_claims_and_transfers(): void
    {
        [$from, $to] = $this->accounts();
        $receipt = app(RecordReceipt::class);
        $transfer = AccountTransfer::create(['from_account_id' => $from->id, 'to_account_id' => $to->id, 'amount' => 5, 'transfer_date' => '2026-03-01', 'comment' => 'Rücklage']);
        $claim = SettlementClaim::create(['lease_id' => $this->lease->id, ...$this->claimInput(['notes' => 'BKA 2025'])]);
        $receipt->attach($transfer, $this->pdf('u.pdf'), 'u.pdf');
        $receipt->attach($claim, $this->pdf('f.pdf'), 'f.pdf');

        $zipPath = app(BackupService::class)->export($this->user->fresh());
        app(BackupService::class)->import($this->user->fresh(), $zipPath);
        unlink($zipPath);

        $restoredTransfer = AccountTransfer::where('comment', 'Rücklage')->whereKeyNot($transfer->id)->sole();
        $restoredClaim = SettlementClaim::where('notes', 'BKA 2025')->whereKeyNot($claim->id)->sole();
        $this->assertSame('u.pdf', $restoredTransfer->file_original_name);
        $this->assertSame('f.pdf', $restoredClaim->file_original_name);
        FileStorage::disk()->assertExists($restoredTransfer->file_path);
        FileStorage::disk()->assertExists($restoredClaim->file_path);
        $this->assertNotSame($claim->file_path, $restoredClaim->file_path);
    }
}
