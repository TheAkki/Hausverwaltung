<?php

namespace Tests\Feature;

use App\Models\BillingTemplate;
use App\Models\Cost;
use App\Models\CostCategory;
use App\Models\Invoice;
use App\Models\InvoiceIssuer;
use App\Models\Landlord;
use App\Models\Lease;
use App\Models\ManagementGroup;
use App\Models\RentalUnit;
use App\Models\SettlementClaim;
use App\Models\Tenant;
use App\Models\User;
use App\Services\BackupService;
use App\Support\FileStorage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use ZipArchive;

// KI-generiert
/**
 * Backup-Format Version 2: Vermieter, Forderungen, Mietvertrags-Kopplung/Vorvertrag,
 * gruppeneigene Kategorien und Vorlagen, Prognosemodus sowie Rechnungen/Kosten an
 * Mietverträgen kommen beim Export mit und werden beim Import wiederhergestellt.
 */
class BackupVersion2Test extends TestCase
{
    use RefreshDatabase;

    private const ADMIN_EMAIL = 'admin@example.test';

    private User $user;

    private ManagementGroup $managementGroup;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake(FileStorage::diskName());
        config(['app.admin_email' => self::ADMIN_EMAIL]);
        $this->user = User::factory()->create();
        $this->managementGroup = $this->buildStructure();
    }

    private function buildStructure(): ManagementGroup
    {
        $group = $this->user->groups()->first();
        FileStorage::disk()->put('billing-templates/kopf.png', 'PNG');
        $template = BillingTemplate::create([
            'user_group_id' => $group->id, 'name' => 'Eigene Vorlage', 'content' => 'x', 'css_content' => 'p{}',
            'header_image_path' => 'billing-templates/kopf.png',
        ]);
        CostCategory::create(['user_group_id' => $group->id, 'name' => 'Eigene Kategorie', 'is_tax_relevant' => false]);
        Landlord::create(['user_group_id' => $group->id, 'name' => 'Konto-Vermieter']);

        $managementGroup = ManagementGroup::create([
            'description' => 'V2', 'user_group_id' => $group->id, 'default_billing_template_id' => $template->id,
            'variable_cost_forecast_mode' => ManagementGroup::VARIABLE_FORECAST_REGRESSION,
        ]);
        Landlord::create(['management_group_id' => $managementGroup->id, 'name' => 'MG-Vermieter']);
        $unit = $managementGroup->managementUnits()->create(['street' => 'S', 'postal_code' => '1', 'city' => 'C']);
        $flat = $unit->rentalUnits()->create(['type' => 'Wohnung', 'is_relevant_for_billing' => true]);
        $garage = $unit->rentalUnits()->create(['type' => 'Garage', 'is_relevant_for_billing' => false]);
        $vacant = $unit->rentalUnits()->create(['type' => 'Stellplatz', 'is_relevant_for_billing' => false]);
        $tenant = Tenant::create([
            'user_group_id' => $group->id, 'salutation' => 'Frau', 'last_name' => 'M', 'street' => 'A', 'postal_code' => '1', 'city' => 'B',
        ]);
        $interval = $managementGroup->billingIntervals()->first();

        $pre = Lease::create(['rental_unit_id' => $flat->id, 'start_date' => '2025-01-01', 'end_date' => '2025-12-31',
            'is_pre_contract' => true, 'rent_adjustment_type' => 'fixed', 'cold_rent' => 400]);
        $main = Lease::create(['rental_unit_id' => $flat->id, 'start_date' => '2026-01-01', 'rent_adjustment_type' => 'fixed', 'cold_rent' => 500]);
        // Gekoppelter Stellplatz-/Garagenvertrag ("Mietvertrag: <ref>") auf den Hauptvertrag.
        $coupled = Lease::create(['rental_unit_id' => $garage->id, 'start_date' => '2026-01-01', 'coupled_to_lease_id' => $main->id,
            'rent_adjustment_type' => 'fixed', 'cold_rent' => 50]);
        foreach ([$pre, $main, $coupled] as $lease) {
            $lease->tenants()->attach($tenant->id);
        }

        SettlementClaim::create(['lease_id' => $main->id, 'billing_interval_id' => $interval->id, 'amount' => -12.5, 'date' => '2026-03-01']);
        SettlementClaim::create(['rental_unit_id' => $vacant->id, 'billing_interval_id' => $interval->id, 'amount' => 7, 'date' => '2026-03-02']);

        $issuer = InvoiceIssuer::create(['user_group_id' => $group->id, 'name' => 'Stadtwerke', 'street' => '', 'postal_code' => '', 'city' => '']);
        $invoice = Invoice::create([
            'invoice_issuer_id' => $issuer->id, 'costable_type' => 'lease', 'costable_id' => $main->id,
            'amount' => 30, 'invoice_date' => '2026-02-01', 'periodicity' => 'once',
        ]);
        $invoice->costs()->create([
            'costable_type' => 'lease', 'costable_id' => $main->id, 'billing_interval_id' => $interval->id,
            'cost_category_id' => CostCategory::where('name', 'Eigene Kategorie')->first()->id, 'amount' => 30, 'period_date' => '2026-02-01',
            'note' => 'Notiz',
        ]);

        return $managementGroup;
    }

    private function exportedData(string $zipPath): array
    {
        $zip = new ZipArchive;
        $zip->open($zipPath);
        $data = json_decode((string) $zip->getFromName('backup.json'), true);
        $zip->close();

        return $data;
    }

    private function importedGroup(): ManagementGroup
    {
        return ManagementGroup::where('description', 'V2')->whereKeyNot($this->managementGroup->id)->firstOrFail();
    }

    public function test_the_export_contains_the_version_2_sections(): void
    {
        $data = $this->exportedData(app(BackupService::class)->export($this->user->fresh()));
        $group = $data['groups'][0];
        $managementGroup = $group['management_groups'][0];
        $leases = $managementGroup['management_units'][0]['rental_units'][0]['leases'];

        $this->assertSame(2, $data['version']);
        $this->assertSame('Konto-Vermieter', $group['landlord']['name']);
        $this->assertSame('Eigene Kategorie', $group['cost_categories'][0]['name']);
        $this->assertSame('p{}', $group['billing_templates'][0]['css_content']);
        $this->assertSame('MG-Vermieter', $managementGroup['landlord']['name']);
        $this->assertTrue($leases[0]['is_pre_contract']);
        $this->assertSame(-12.5, (float) $leases[1]['settlement_claims'][0]['amount']);
        $this->assertSame($leases[1]['ref'], $managementGroup['management_units'][0]['rental_units'][1]['leases'][0]['coupled_to_lease_ref']);
        $this->assertCount(1, $managementGroup['management_units'][0]['rental_units'][2]['settlement_claims']);
        $this->assertSame('lease', $managementGroup['invoices'][0]['costable']['type']);
        $this->assertSame('Notiz', $managementGroup['costs'][0]['note']);
        $this->assertSame('Frau', $group['tenants'][0]['salutation']);
    }

    public function test_importing_restores_every_version_2_section(): void
    {
        $path = app(BackupService::class)->export($this->user->fresh());
        app(BackupService::class)->import($this->user->fresh(), $path);

        $imported = $this->importedGroup();
        $this->assertSame($this->managementGroup->variable_cost_forecast_mode, $imported->variable_cost_forecast_mode);
        $this->assertSame('MG-Vermieter', $imported->landlord->name);
        $this->assertSame('Eigene Vorlage', $imported->defaultBillingTemplate->name);
        $this->assertSame($imported->user_group_id, $imported->defaultBillingTemplate->user_group_id);
        FileStorage::disk()->assertExists($imported->defaultBillingTemplate->header_image_path);
        $this->assertSame('Konto-Vermieter', Landlord::where('user_group_id', $imported->user_group_id)->value('name'));
        $this->assertFalse(CostCategory::where('user_group_id', $imported->user_group_id)->where('name', 'Eigene Kategorie')->firstOrFail()->is_tax_relevant);

        $rentalUnits = RentalUnit::whereIn('management_unit_id', $imported->managementUnits()->pluck('id'))->orderBy('id')->get();
        [$pre, $main] = $rentalUnits[0]->leases()->orderBy('start_date')->get();
        $coupled = $rentalUnits[1]->leases()->first();
        $this->assertTrue($pre->is_pre_contract);
        $this->assertSame($main->id, $coupled->coupled_to_lease_id);
        $this->assertSame(1, $main->settlementClaims()->count());
        $this->assertSame(1, $rentalUnits[2]->settlementClaims()->count());

        $cost = Cost::where('costable_type', 'lease')->where('costable_id', $main->id)->firstOrFail();
        $this->assertSame('Notiz', $cost->note);
        $this->assertSame('lease', $cost->invoice->costable_type);
    }

    public function test_dangling_references_are_skipped_on_import(): void
    {
        $data = $this->exportedData(app(BackupService::class)->export($this->user->fresh()));
        $units = &$data['groups'][0]['management_groups'][0]['management_units'][0]['rental_units'];
        $units[1]['leases'][0]['coupled_to_lease_ref'] = 999999;
        $units[2]['settlement_claims'][0]['billing_interval_ref'] = 999999;
        unset($units);

        $zipPath = tempnam(sys_get_temp_dir(), 'hv-test-');
        $zip = new ZipArchive;
        $zip->open($zipPath, ZipArchive::OVERWRITE);
        $zip->addFromString('backup.json', json_encode($data));
        $zip->close();

        app(BackupService::class)->import($this->user->fresh(), $zipPath);

        $rentalUnits = RentalUnit::whereIn('management_unit_id', $this->importedGroup()->managementUnits()->pluck('id'))->orderBy('id')->get();
        $this->assertNull($rentalUnits[1]->leases()->first()->coupled_to_lease_id);
        $this->assertSame(0, $rentalUnits[2]->settlementClaims()->count());
    }

    public function test_the_full_export_only_lists_system_wide_templates_and_categories(): void
    {
        BillingTemplate::create(['name' => 'System', 'content' => 'x']);
        CostCategory::create(['name' => 'Systemweit']);
        $admin = User::factory()->create(['email' => self::ADMIN_EMAIL]);

        $data = $this->exportedData(app(BackupService::class)->exportFull($admin));

        $this->assertSame(['System'], array_column($data['billing_templates'], 'name'));
        $this->assertSame(['Systemweit'], array_column($data['cost_categories'], 'name'));
    }
}
