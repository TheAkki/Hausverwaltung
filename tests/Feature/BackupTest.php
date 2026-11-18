<?php

namespace Tests\Feature;

use App\Models\AccountTransfer;
use App\Models\BillingTemplate;
use App\Models\CostCategory;
use App\Models\Group;
use App\Models\Invoice;
use App\Models\InvoiceIssuer;
use App\Models\Lease;
use App\Models\ManagementGroup;
use App\Models\Tenant;
use App\Models\Theme;
use App\Models\User;
use App\Services\BackupService;
use App\Services\InvoiceSeries\InvoiceSeriesService;
use App\Services\RentPaymentGenerator;
use Database\Seeders\CostCategorySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use ZipArchive;

class BackupTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Baut eine kleine, aber vollständige Verwaltungsstruktur für den Benutzer:
     * Gruppe → Verwaltungsgruppe → Verwaltungseinheit → Mieteinheit → Mietvertrag
     * (mit Mieter und Mietzahlungen) plus Rechnung (mit Datei und Kostenzeilen).
     */
    private function buildStructure(User $user): ManagementGroup
    {
        $this->seed(CostCategorySeeder::class);

        $group = $user->groups()->first();

        $managementGroup = ManagementGroup::create([
            'description' => 'Backup MG',
            'user_group_id' => $group->id,
            'billing_start' => '2026-01-01',
            'kontosystem_enabled' => true,
        ]);
        $managementGroup->costCategories()
            ->syncWithoutDetaching([CostCategory::first()->id => ['allocation_key' => 'area']]);
        $managementGroup->maintenanceContracts()->create([
            'name' => 'Heizungswartung', 'type' => 'Wartung', 'provider' => 'Firma X',
            'start_date' => '2026-01-01',
        ]);
        $managementUnit = $managementGroup->managementUnits()->create([
            'street' => 'Backupweg 1', 'postal_code' => '11111', 'city' => 'Backupstadt',
        ]);
        $rentalUnit = $managementUnit->rentalUnits()->create([
            'type' => 'Wohnung', 'size' => 50, 'is_relevant_for_billing' => true,
        ]);

        $tenant = Tenant::create([
            'user_group_id' => $group->id,
            'first_name' => 'Max', 'last_name' => 'Mustermann',
            'street' => 'A', 'postal_code' => '1', 'city' => 'B',
        ]);
        $lease = Lease::create([
            'rental_unit_id' => $rentalUnit->id, 'start_date' => '2026-01-01',
            'rent_adjustment_type' => 'fixed', 'cold_rent' => 500,
        ]);
        $lease->tenants()->attach($tenant->id);
        app(RentPaymentGenerator::class)->generate($lease->leaseIntervals()->first());
        Storage::disk('public')->put('lease_documents/vertrag.pdf', 'PDF-Inhalt Mietvertrag');
        $lease->documents()->create([
            'file_path' => 'lease_documents/vertrag.pdf',
            'original_name' => 'Mietvertrag.pdf',
        ]);

        $issuer = InvoiceIssuer::create([
            'user_group_id' => $group->id,
            'name' => 'Stadtwerke', 'street' => 'A', 'postal_code' => '1', 'city' => 'B',
        ]);
        Storage::disk('public')->put('invoices/rechnung.pdf', 'PDF-Inhalt Rechnung');
        $invoice = Invoice::create([
            'invoice_issuer_id' => $issuer->id,
            'costable_type' => 'management_unit',
            'costable_id' => $managementUnit->id,
            'amount' => 100,
            'invoice_date' => '2026-02-01',
            'booking_date' => '2026-02-01',
            'periodicity' => 'once',
            'description' => 'Backup-Rechnung',
            'file_path' => 'invoices/rechnung.pdf',
            'file_original_name' => 'Rechnung.pdf',
        ]);
        app(InvoiceSeriesService::class)->write($invoice, $managementUnit, CostCategory::first());

        // Kontosystem: zwei Konten, eine Umbuchung sowie Konto-Zuordnungen an einer
        // Kostenzeile und einer Mietzahlung.
        $accountA = $managementGroup->accounts()->create(['name' => 'Girokonto', 'initial_balance' => 1000, 'is_default' => true]);
        $accountB = $managementGroup->accounts()->create(['name' => 'Rücklagen', 'initial_balance' => 0, 'is_default' => false]);
        AccountTransfer::create([
            'from_account_id' => $accountA->id, 'to_account_id' => $accountB->id,
            'amount' => 50, 'transfer_date' => '2026-03-01', 'comment' => 'Rücklage',
        ]);
        $invoice->costs()->first()->accounts()->attach($accountA->id, ['amount' => 100]);
        $lease->leaseIntervals()->first()->rentPayments()->first()->accounts()->attach($accountA->id, ['amount' => 500]);

        // Eine manuelle Rechnung mit expliziten Vorkommen.
        $manual = Invoice::create([
            'invoice_issuer_id' => $issuer->id,
            'costable_type' => 'management_group',
            'costable_id' => $managementGroup->id,
            'amount' => 70,
            'invoice_date' => '2026-01-01',
            'periodicity' => 'manual',
            'description' => 'Manuelle Rechnung',
        ]);
        $manual->occurrences()->create(['occurrence_date' => '2026-02-01', 'amount' => 20]);

        return $managementGroup;
    }

    public function test_a_user_can_export_their_data_as_a_zip_containing_backup_json_and_files(): void
    {
        Storage::fake('public');

        $user = User::factory()->create();
        $this->buildStructure($user);

        $response = $this->actingAs($user)->get(route('backup.export'));

        $response->assertOk();
        $response->assertDownload();

        $zip = new ZipArchive;
        $this->assertTrue($zip->open($response->getFile()->getPathname()));
        $json = json_decode((string) $zip->getFromName('backup.json'), true);

        $this->assertSame(BackupService::FORMAT, $json['format']);
        $this->assertSame('user', $json['type']);
        $this->assertSame($user->email, $json['profile']['email']);
        // Die eigene Gruppe samt Verwaltungsstruktur ist enthalten …
        $this->assertCount(1, $json['groups']);
        $this->assertSame('Backup MG', $json['groups'][0]['management_groups'][0]['description']);
        // … und die angehängten Dateien liegen als files/-Einträge im Archiv.
        $this->assertNotFalse($zip->getFromName('files/invoices/rechnung.pdf'));
        $zip->close();
    }

    public function test_importing_an_export_restores_the_whole_structure_with_permissions(): void
    {
        Storage::fake('public');

        $user = User::factory()->create();
        $managementGroup = $this->buildStructure($user);

        // Ein zweites Gruppenmitglied mit Rolle "user", dessen Berechtigung der
        // Import wiederherstellen muss, und ein drittes, das es auf dem Zielsystem
        // nicht mehr gibt.
        $member = User::factory()->create();
        $user->groups()->first()->users()->attach($member->id, ['role' => 'user']);
        $gone = User::factory()->create();
        $user->groups()->first()->users()->attach($gone->id, ['role' => 'user']);

        $zipPath = app(BackupService::class)->export($user->fresh());

        // KI-generiert: Ein Benutzer-Import legt keine Konten an (sonst könnte eine
        // präparierte Datei Konten mit leerem Passwort erzeugen) – das fehlende
        // Mitglied wird übersprungen und in der Zusammenfassung genannt.
        $goneEmail = $gone->email;
        $gone->groups()->detach();
        $gone->delete();

        $groupsBefore = Group::count();

        $summary = app(BackupService::class)->import($user->fresh(), $zipPath);
        unlink($zipPath);

        $this->assertStringContainsString('1 Gruppe(n) importiert.', $summary);
        $this->assertStringContainsString('1 Mitglied(er) ohne Konto', $summary);
        $this->assertSame($groupsBefore + 1, Group::count());
        $this->assertNull(User::where('email', $goneEmail)->first());

        $imported = ManagementGroup::where('description', 'Backup MG')
            ->where('id', '!=', $managementGroup->id)
            ->first();
        $this->assertNotNull($imported);

        // Struktur unterhalb der Verwaltungsgruppe ist vollständig wiederhergestellt.
        $unit = $imported->managementUnits()->first();
        $this->assertSame('Backupweg 1', $unit->street);
        $rentalUnit = $unit->rentalUnits()->first();
        $lease = $rentalUnit->leases()->first();
        $this->assertNotNull($lease);
        // Der LeaseObserver darf beim Import kein zusätzliches Intervall anlegen:
        // es gibt exakt das eine Intervall aus dem Backup.
        $this->assertSame(1, $lease->leaseIntervals()->count());
        $this->assertSame('Mustermann', $lease->tenants()->first()->last_name);
        // Mietzahlungen des Intervalls sind mitgekommen.
        $this->assertGreaterThan(0, $lease->leaseIntervals()->first()->rentPayments()->count());
        // Angehängte Dateien wurden unter neuem Namen wiederhergestellt.
        $document = $lease->documents()->first();
        $this->assertNotNull($document);
        $this->assertSame('Mietvertrag.pdf', $document->original_name);
        Storage::disk('public')->assertExists($document->file_path);

        // Rechnung samt Datei und Kostenzeile hängt an der importierten Einheit.
        $invoice = Invoice::where('costable_type', 'management_unit')->where('costable_id', $unit->id)->first();
        $this->assertNotNull($invoice);
        $this->assertSame('Backup-Rechnung', $invoice->description);
        Storage::disk('public')->assertExists($invoice->file_path);
        $this->assertSame(1, $invoice->costs()->count());

        // Kontosystem: Konten, Umbuchung und Konto-Zuordnungen sind wiederhergestellt.
        $this->assertTrue($imported->kontosystem_enabled);
        $this->assertSame(['Girokonto', 'Rücklagen'], $imported->accounts()->orderBy('id')->pluck('name')->all());
        $giro = $imported->accounts()->where('name', 'Girokonto')->first();
        $this->assertTrue($giro->is_default);
        $this->assertSame(1, AccountTransfer::where('from_account_id', $giro->id)->count());
        $this->assertSame(100.0, (float) $invoice->costs()->first()->accounts()->first()->pivot->amount);
        $this->assertSame(500.0, (float) $lease->leaseIntervals()->first()->rentPayments()->first()->accounts()->first()->pivot->amount);

        // Manuelle Rechnung mit Vorkommen, Wartungsvertrag und Kategorien-Zuordnung.
        $manual = Invoice::where('costable_type', 'management_group')->where('costable_id', $imported->id)->first();
        $this->assertNotNull($manual);
        $this->assertSame(1, $manual->occurrences()->count());
        $this->assertSame('Heizungswartung', $imported->maintenanceContracts()->first()->name);
        $this->assertSame('area', $imported->costCategories()->first()->pivot->allocation_key);

        // Berechtigungen: das vorhandene Mitglied hat seine Rolle in der neu
        // angelegten Gruppe zurück, der Importierende ist Admin und Besitzer.
        $originalGroup = $user->groups()->first();
        $importedGroup = Group::where('name', $originalGroup->name)->where('id', '!=', $originalGroup->id)->first();
        $this->assertNotNull($importedGroup);
        $this->assertSame('user', $importedGroup->users()->where('users.id', $member->id)->first()->pivot->role);
        $this->assertSame('admin', $importedGroup->users()->where('users.id', $user->id)->first()->pivot->role);
        $this->assertSame($user->id, $importedGroup->owner_id);
    }

    /**
     * Konzept.md/Nutzerhinweis ("Warum sehe ich Heizung und Heizkosten? Die Kategorie
     * Heizung hat der Account gar nicht."): eine dem Account gehörende (nicht
     * systemweite) Kategorie darf beim Import nicht in eine fremde Gruppe
     * "durchsickern" – sie wird der neu importierten Gruppe zugeordnet. Eine
     * wiederverwendete systemweite Kategorie bleibt dabei systemweit und wird nicht
     * dupliziert.
     */
    public function test_importing_preserves_custom_category_scoping_and_reuses_systemweite_categories(): void
    {
        Storage::fake('public');

        $user = User::factory()->create();
        $managementGroup = $this->buildStructure($user);
        $userGroup = $user->groups()->first();

        $ownCategory = CostCategory::create([
            'user_group_id' => $userGroup->id,
            'name' => 'Eigene Sonderkategorie',
            'is_relevant_for_billing' => true,
        ]);
        $managementGroup->costCategories()->syncWithoutDetaching([$ownCategory->id => ['allocation_key' => 'individual']]);

        $categoriesBefore = CostCategory::count();

        $zipPath = app(BackupService::class)->export($user->fresh());
        app(BackupService::class)->import($user->fresh(), $zipPath);
        unlink($zipPath);

        $imported = ManagementGroup::where('description', 'Backup MG')
            ->where('id', '!=', $managementGroup->id)
            ->first();
        $importedGroup = Group::where('id', '!=', $userGroup->id)
            ->whereHas('users', fn ($q) => $q->where('users.id', $user->id))
            ->first();

        // Die eigene Kategorie kam mit herüber, scoped auf die neu importierte Gruppe –
        // die bereits vorhandene systemweite "area"-Kategorie aus buildStructure() wurde
        // wiederverwendet statt dupliziert.
        $this->assertSame($categoriesBefore + 1, CostCategory::count());

        $importedOwnCategory = $imported->costCategories()->where('name', 'Eigene Sonderkategorie')->first();
        $this->assertNotNull($importedOwnCategory);
        $this->assertSame($importedGroup->id, $importedOwnCategory->user_group_id);
        $this->assertSame('individual', $importedOwnCategory->pivot->allocation_key);

        $importedAreaCategory = $imported->costCategories()->wherePivot('allocation_key', 'area')->first();
        $this->assertNotNull($importedAreaCategory);
        $this->assertNull($importedAreaCategory->user_group_id);
    }

    public function test_only_the_administrator_may_download_the_full_export(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get(route('backup.export-full'))->assertForbidden();
    }

    public function test_the_administrator_can_export_and_import_all_settings(): void
    {
        Storage::fake('public');

        $user = User::factory()->create();
        $this->buildStructure($user);

        $admin = User::factory()->create(['email' => config('app.admin_email')]);

        $response = $this->actingAs($admin)->get(route('backup.export-full'));
        $response->assertOk();

        $zip = new ZipArchive;
        $this->assertTrue($zip->open($response->getFile()->getPathname()));
        $json = json_decode((string) $zip->getFromName('backup.json'), true);
        $zip->close();

        $this->assertSame('full', $json['type']);
        // Alle Benutzer und die globalen Referenzdaten sind enthalten.
        $this->assertContains($user->email, array_column($json['users'], 'email'));
        $this->assertNotEmpty($json['cost_categories']);
        // Alle Gruppen (auch die persönliche des Admins) sind enthalten.
        $this->assertGreaterThanOrEqual(2, count($json['groups']));
    }

    public function test_a_full_backup_import_restores_users_reference_data_and_groups(): void
    {
        Storage::fake('public');

        $user = User::factory()->create();
        $this->buildStructure($user);

        // Globale Referenzdaten, die der Gesamt-Export mitnehmen muss: ein eigenes
        // Theme und eine Abrechnungsvorlage mit Kopfbild.
        $theme = Theme::create(['name' => 'Eigenes Theme', 'slug' => 'eigenes-theme', 'css' => 'body { color: red; }', 'is_builtin' => false]);
        Storage::disk('public')->put('billing-templates/kopf.png', 'PNG');
        BillingTemplate::create([
            'name' => 'Backup-Vorlage', 'content' => 'Inhalt', 'header' => 'Kopf', 'footer' => 'Fuß',
            'header_image_path' => 'billing-templates/kopf.png',
        ]);

        // Ein Mitglied, das auf dem "Zielsystem" fehlen wird.
        $member = User::factory()->create(['theme_id' => $theme->id]);
        $user->groups()->first()->users()->attach($member->id, ['role' => 'user']);

        $admin = User::factory()->create(['email' => config('app.admin_email')]);
        $zipPath = app(BackupService::class)->exportFull($admin);

        // "Zielsystem" simulieren: Mitglied, Theme und Vorlage existieren nicht mehr.
        $memberEmail = $member->email;
        $member->groups()->detach();
        $member->delete();
        BillingTemplate::where('name', 'Backup-Vorlage')->delete();
        $theme->delete();

        $summary = app(BackupService::class)->import($admin->fresh(), $zipPath);
        unlink($zipPath);

        $this->assertNotSame('', $summary);

        // Benutzer wiederhergestellt: ohne Passwort, mit Passwortzwang und Theme.
        $recreated = User::where('email', $memberEmail)->first();
        $this->assertNotNull($recreated);
        $this->assertTrue($recreated->must_change_password);
        $this->assertSame('eigenes-theme', $recreated->theme?->slug);

        // Referenzdaten wiederhergestellt, inklusive Kopfbild-Datei.
        $this->assertNotNull(Theme::where('slug', 'eigenes-theme')->first());
        $template = BillingTemplate::where('name', 'Backup-Vorlage')->first();
        $this->assertNotNull($template);
        Storage::disk('public')->assertExists($template->header_image_path);

        // Die Gruppen samt Struktur wurden (als neue Kopien) wiederhergestellt.
        $this->assertSame(2, ManagementGroup::where('description', 'Backup MG')->count());
    }

    public function test_a_file_that_is_no_zip_archive_is_rejected_by_the_service(): void
    {
        $user = User::factory()->create();

        $path = tempnam(sys_get_temp_dir(), 'no-zip');
        file_put_contents($path, 'nur Text, kein Archiv');

        try {
            $this->expectException(\RuntimeException::class);
            app(BackupService::class)->import($user, $path);
        } finally {
            unlink($path);
        }
    }

    public function test_a_zip_whose_backup_json_has_the_wrong_format_is_rejected(): void
    {
        $user = User::factory()->create();

        $path = tempnam(sys_get_temp_dir(), 'wrong-format');
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::OVERWRITE);
        $zip->addFromString('backup.json', (string) json_encode(['format' => 'etwas-anderes']));
        $zip->close();

        try {
            $this->expectException(\RuntimeException::class);
            app(BackupService::class)->import($user, $path);
        } finally {
            unlink($path);
        }
    }

    public function test_a_normal_user_cannot_import_a_full_backup(): void
    {
        Storage::fake('public');

        $admin = User::factory()->create(['email' => config('app.admin_email')]);
        $zipPath = app(BackupService::class)->exportFull($admin);

        $user = User::factory()->create();

        $this->expectException(\RuntimeException::class);

        try {
            app(BackupService::class)->import($user, $zipPath);
        } finally {
            unlink($zipPath);
        }
    }

    public function test_an_upload_that_is_not_a_backup_archive_is_rejected_with_an_error(): void
    {
        $user = User::factory()->create();

        $zipPath = tempnam(sys_get_temp_dir(), 'not-a-backup');
        $zip = new ZipArchive;
        $zip->open($zipPath, ZipArchive::OVERWRITE);
        $zip->addFromString('irgendwas.txt', 'kein Backup');
        $zip->close();

        $response = $this->actingAs($user)
            ->from(route('profile.edit'))
            ->post(route('backup.import'), [
                'backup' => new UploadedFile($zipPath, 'backup.zip', 'application/zip', null, true),
            ]);

        $response->assertRedirect(route('profile.edit'));
        $response->assertSessionHasErrors('backup');
    }

    public function test_the_import_can_be_uploaded_through_the_profile_form(): void
    {
        Storage::fake('public');

        $user = User::factory()->create();
        $this->buildStructure($user);

        $zipPath = app(BackupService::class)->export($user->fresh());

        $response = $this->actingAs($user)
            ->from(route('profile.edit'))
            ->post(route('backup.import'), [
                'backup' => new UploadedFile($zipPath, 'backup.zip', 'application/zip', null, true),
            ]);

        $response->assertRedirect(route('profile.edit'));
        $response->assertSessionHas('status');
        $this->assertSame(2, ManagementGroup::where('description', 'Backup MG')->count());
    }

    public function test_the_profile_page_shows_the_backup_section(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get(route('profile.edit'));

        $response->assertOk();
        $response->assertSee(__('Daten exportieren'));
        $response->assertSee(route('backup.import'), false);
        // Der Gesamt-Export ist normalen Benutzern nicht sichtbar …
        $response->assertDontSee(route('backup.export-full'), false);

        // … dem Administrator aber schon.
        $admin = User::factory()->create(['email' => config('app.admin_email')]);
        $this->actingAs($admin)->get(route('profile.edit'))
            ->assertSee(route('backup.export-full'), false);
    }
}
