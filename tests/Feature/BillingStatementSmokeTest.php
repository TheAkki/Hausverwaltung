<?php

namespace Tests\Feature;

use App\Models\BillingInterval;
use App\Models\BillingTemplate;
use App\Models\CostCategory;
use App\Models\Invoice;
use App\Models\InvoiceIssuer;
use App\Models\Landlord;
use App\Models\Lease;
use App\Models\ManagementGroup;
use App\Models\ManagementUnit;
use App\Models\Tenant;
use App\Models\User;
use App\Services\InvoiceSeries\InvoiceSeriesService;
use App\Support\FileStorage;
use Database\Seeders\BillingTemplateSeeder;
use Database\Seeders\CostCategorySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class BillingStatementSmokeTest extends TestCase
{
    use RefreshDatabase;

    public function test_billing_statement_can_be_previewed_and_downloaded_as_pdf(): void
    {
        $this->seed(CostCategorySeeder::class);
        $this->seed(BillingTemplateSeeder::class);

        $user = User::factory()->create();
        $group = $user->groups()->first();

        $managementGroup = ManagementGroup::create([
            'description' => 'Smoke Test Group',
            'user_group_id' => $group->id,
        ]);

        $managementUnit = $managementGroup->managementUnits()->create([
            'street' => 'Teststraße 1',
            'postal_code' => '12345',
            'city' => 'Teststadt',
        ]);

        $rentalUnit = $managementUnit->rentalUnits()->create([
            'type' => 'Wohnung',
            'size' => 60,
            'is_relevant_for_billing' => true,
        ]);

        $tenant = Tenant::create([
            'first_name' => 'Max',
            'last_name' => 'Mustermann',
            'street' => 'Musterweg 2',
            'postal_code' => '54321',
            'city' => 'Musterstadt',
        ]);

        $lease = Lease::create([
            'rental_unit_id' => $rentalUnit->id,
            'start_date' => '2026-01-01',
            'rent_adjustment_type' => 'fixed',
            'cold_rent' => 500,
        ]);
        $lease->tenants()->attach($tenant->id);

        $issuer = InvoiceIssuer::create([
            'name' => 'Stadtwerke',
            'street' => 'A',
            'postal_code' => '1',
            'city' => 'B',
        ]);
        $category = CostCategory::first();

        $invoice = Invoice::create([
            'invoice_issuer_id' => $issuer->id,
            'amount' => 120,
            'invoice_date' => '2026-01-15',
            'periodicity' => 'once',
        ]);
        app(InvoiceSeriesService::class)->write($invoice, $managementUnit, $category);

        $billingInterval = $managementGroup->billingIntervals()->first();

        $this->assertNotNull(BillingTemplate::where('is_default', true)->first());

        $preview = $this->actingAs($user)->get(
            route('billing-statements.show', [$managementUnit, $billingInterval])
        );
        $preview->assertStatus(200);
        $preview->assertSee('Mustermann');
        $preview->assertSee('Betriebskostenabrechnung');
        $preview->assertSee('Vorauszahlungen');
        $preview->assertSee('Januar 2026');

        $pdf = $this->actingAs($user)->get(
            route('billing-statements.pdf', [$managementUnit, $billingInterval])
        );
        $pdf->assertStatus(200);
        $pdf->assertHeader('content-type', 'application/pdf');
    }

    /**
     * Nutzerwunsch: "Wohnfläche (Verwaltungsgruppe)" im Standard-Template nur
     * anzeigen, wenn tatsächlich abrechnungsrelevante Buchungen auf
     * Verwaltungsgruppen-Ebene (Stufe 1) erfolgt sind – sonst ist die Angabe für
     * diesen Mietvertrag irrelevant (er trägt ohnehin keinen Anteil davon).
     */
    public function test_the_management_groups_wohnflaeche_row_only_appears_when_it_has_billing_relevant_costs(): void
    {
        $this->seed(CostCategorySeeder::class);
        $this->seed(BillingTemplateSeeder::class);

        $user = User::factory()->create();
        $group = $user->groups()->first();
        $managementGroup = ManagementGroup::create(['description' => 'MG', 'user_group_id' => $group->id]);
        $managementUnit = $managementGroup->managementUnits()->create([
            'street' => 'Teststraße 1', 'postal_code' => '12345', 'city' => 'Teststadt',
        ]);
        $rentalUnit = $managementUnit->rentalUnits()->create(['type' => 'Wohnung', 'size' => 60, 'is_relevant_for_billing' => true]);
        $tenant = Tenant::create(['first_name' => 'Max', 'last_name' => 'Mustermann', 'street' => 'A', 'postal_code' => '1', 'city' => 'B']);
        $lease = Lease::create(['rental_unit_id' => $rentalUnit->id, 'start_date' => '2026-01-01', 'rent_adjustment_type' => 'fixed', 'cold_rent' => 500]);
        $lease->tenants()->attach($tenant->id);

        $billingInterval = $managementGroup->billingIntervals()->first();

        $withoutGroupCosts = $this->actingAs($user)->get(route('billing-statements.show', [$managementUnit, $billingInterval]));
        $withoutGroupCosts->assertOk();
        $withoutGroupCosts->assertDontSee('Wohnfläche (Verwaltungsgruppe)');

        $category = CostCategory::create(['name' => 'Hausverwaltungskosten', 'is_relevant_for_billing' => true]);
        $managementGroup->costCategories()->attach($category->id, ['allocation_key' => 'area']);
        $managementGroup->costs()->create([
            'billing_interval_id' => $billingInterval->id, 'cost_category_id' => $category->id,
            'amount' => 120, 'period_date' => '2026-06-01',
        ]);

        $withGroupCosts = $this->actingAs($user)->get(route('billing-statements.show', [$managementUnit, $billingInterval]));
        $withGroupCosts->assertOk();
        $withGroupCosts->assertSee('Wohnfläche (Verwaltungsgruppe)');
    }

    /**
     * Regression: a negative Stufe-Anteil (z. B. eine Gutschrift/Rückerstattung auf
     * Verwaltungsgruppen-Ebene) muss die Zeile/den Abschnitt weiterhin anzeigen –
     * "> 0" hätte einen negativen (aber echten, ungleich null) Anteil fälschlich
     * ausgeblendet, obwohl abrechnungsrelevante Buchungen erfolgt sind.
     */
    public function test_a_negative_management_group_share_still_shows_the_wohnflaeche_row_and_cost_table(): void
    {
        $this->seed(CostCategorySeeder::class);
        $this->seed(BillingTemplateSeeder::class);

        $user = User::factory()->create();
        $group = $user->groups()->first();
        $managementGroup = ManagementGroup::create(['description' => 'MG', 'user_group_id' => $group->id]);
        $managementUnit = $managementGroup->managementUnits()->create([
            'street' => 'Teststraße 1', 'postal_code' => '12345', 'city' => 'Teststadt',
        ]);
        $rentalUnit = $managementUnit->rentalUnits()->create(['type' => 'Wohnung', 'size' => 60, 'is_relevant_for_billing' => true]);
        $tenant = Tenant::create(['first_name' => 'Max', 'last_name' => 'Mustermann', 'street' => 'A', 'postal_code' => '1', 'city' => 'B']);
        $lease = Lease::create(['rental_unit_id' => $rentalUnit->id, 'start_date' => '2026-01-01', 'rent_adjustment_type' => 'fixed', 'cold_rent' => 500]);
        $lease->tenants()->attach($tenant->id);

        $billingInterval = $managementGroup->billingIntervals()->first();

        $category = CostCategory::create(['name' => 'Gutschrift Hausverwaltung', 'is_relevant_for_billing' => true]);
        $managementGroup->costCategories()->attach($category->id, ['allocation_key' => 'area']);
        $managementGroup->costs()->create([
            'billing_interval_id' => $billingInterval->id, 'cost_category_id' => $category->id,
            'amount' => -120, 'period_date' => '2026-06-01',
        ]);

        $response = $this->actingAs($user)->get(route('billing-statements.show', [$managementUnit, $billingInterval]));
        $response->assertOk();
        $response->assertSee('Wohnfläche (Verwaltungsgruppe)');
        $response->assertSee('Wohneinheiten (Verwaltungsgruppe)');
        // Beweist, dass die "Verwaltungsgruppe"-Kostentabelle (nicht nur die Info-
        // Tabellenzeilen) trotz negativem Anteil ebenfalls noch gerendert wird.
        $response->assertSee('Gutschrift Hausverwaltung');
    }

    /**
     * Regression: the header image used to be embedded as a plain storage URL, which
     * DomPDF silently refuses to fetch (its "enable_remote" option defaults to false),
     * so the image never appeared in the PDF export even though it did in the HTML
     * preview. It's now embedded as a base64 data URI instead – no fetch involved.
     */
    public function test_the_header_image_is_embedded_as_a_data_uri_not_a_storage_link(): void
    {
        Storage::fake(FileStorage::diskName());
        $this->seed(CostCategorySeeder::class);

        // Echte PNG-Bytes schreiben (nicht UploadedFile::fake()->create(), dessen
        // Größenangabe unter Storage::fake() keine tatsächlich lesbaren Bytes erzeugt):
        // ein minimales 1x1-Pixel-PNG.
        $pngBytes = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=');
        $imagePath = 'billing-template-images/logo.png';
        FileStorage::disk()->put($imagePath, $pngBytes);
        $template = BillingTemplate::create([
            'name' => 'Mit Kopf-Bild',
            'is_default' => true,
            'header_image_path' => $imagePath,
            'header' => '<img src="{{ $headerImage }}" alt="logo">',
            'content' => '<html><body>{{ $managementUnit->street }}</body></html>',
        ]);

        $user = User::factory()->create();
        $group = $user->groups()->first();
        $managementGroup = ManagementGroup::create(['description' => 'MG', 'user_group_id' => $group->id]);
        $managementUnit = $managementGroup->managementUnits()->create([
            'street' => 'Teststraße 1', 'postal_code' => '12345', 'city' => 'Teststadt',
        ]);
        $billingInterval = $managementGroup->billingIntervals()->first();

        $response = $this->actingAs($user)->get(
            route('billing-statements.show', [$managementUnit, $billingInterval])
        );

        $response->assertOk();
        $response->assertSee('data:image/png;base64,'.base64_encode($pngBytes), false);
        $response->assertDontSee(FileStorage::disk()->url($imagePath), false);
    }

    /**
     * Regression ("Muster-GbR": Text im PDF lag über dem Kopfbereich):
     * ein Kopf-Bild plus mehrzeiliger Text kann höher sein als der im Template per
     *
     * @page deklarierte margin_top – mPDF muss den Content-Bereich dann automatisch
     * nach unten schieben (setAutoTopMargin: "stretch"), statt ihn dort beginnen zu
     * lassen, wo der Content-Margin es sagt, während die Kopfzeile visuell noch
     * darüber hinausragt.
     */
    public function test_a_header_taller_than_the_declared_page_margin_does_not_break_pdf_rendering(): void
    {
        $template = BillingTemplate::create([
            'name' => 'Hohe Kopfzeile',
            'is_default' => true,
            // Extra hoch: ein "Bild" (per CSS-Höhe simuliert) plus zwei Textzeilen,
            // deutlich mehr als die 5mm margin_top unten.
            'header' => '<div style="height: 40mm; background: #eee;"></div><b>Hier entsteht Raum für Neues</b><br>Untertitel',
            'content' => '<html><head><style>@page { margin: 5mm 5mm 5mm 5mm; }</style></head><body>{{ $managementUnit->street }}</body></html>',
        ]);

        $user = User::factory()->create();
        $group = $user->groups()->first();
        $managementGroup = ManagementGroup::create(['description' => 'MG', 'user_group_id' => $group->id]);
        $managementUnit = $managementGroup->managementUnits()->create([
            'street' => 'Teststraße 1', 'postal_code' => '12345', 'city' => 'Teststadt',
        ]);
        $billingInterval = $managementGroup->billingIntervals()->first();

        $response = $this->actingAs($user)->get(
            route('billing-statements.pdf', [$managementUnit, $billingInterval])
        );

        $response->assertOk();
        $response->assertHeader('content-type', 'application/pdf');
    }

    public function test_non_billable_categories_are_excluded_from_the_billing_statement(): void
    {
        $this->seed(CostCategorySeeder::class);
        $this->seed(BillingTemplateSeeder::class);

        $user = User::factory()->create();
        $group = $user->groups()->first();

        $managementGroup = ManagementGroup::create([
            'description' => 'Non-Billable Category Test Group',
            'user_group_id' => $group->id,
        ]);

        $managementUnit = $managementGroup->managementUnits()->create([
            'street' => 'Teststraße 1',
            'postal_code' => '12345',
            'city' => 'Teststadt',
        ]);

        $rentalUnit = $managementUnit->rentalUnits()->create([
            'type' => 'Wohnung',
            'size' => 60,
            'is_relevant_for_billing' => true,
        ]);

        $tenant = Tenant::create([
            'first_name' => 'Erika',
            'last_name' => 'Musterfrau',
            'street' => 'Musterweg 2',
            'postal_code' => '54321',
            'city' => 'Musterstadt',
        ]);

        $lease = Lease::create([
            'rental_unit_id' => $rentalUnit->id,
            'start_date' => '2026-01-01',
            'rent_adjustment_type' => 'fixed',
            'cold_rent' => 500,
        ]);
        $lease->tenants()->attach($tenant->id);

        $issuer = InvoiceIssuer::create([
            'name' => 'Bank',
            'street' => 'A',
            'postal_code' => '1',
            'city' => 'B',
        ]);
        $billableCategory = CostCategory::where('name', 'Heizkosten')->first();
        $zinsenCategory = CostCategory::where('name', 'Zinsen')->first();
        $this->assertFalse($zinsenCategory->is_relevant_for_billing);

        $billableInvoice = Invoice::create([
            'invoice_issuer_id' => $issuer->id,
            'amount' => 100,
            'invoice_date' => '2026-01-15',
            'periodicity' => 'once',
        ]);
        app(InvoiceSeriesService::class)->write($billableInvoice, $managementUnit, $billableCategory);

        $zinsenInvoice = Invoice::create([
            'invoice_issuer_id' => $issuer->id,
            'amount' => 250,
            'invoice_date' => '2026-01-15',
            'periodicity' => 'once',
        ]);
        app(InvoiceSeriesService::class)->write($zinsenInvoice, $managementUnit, $zinsenCategory);

        $billingInterval = $managementGroup->billingIntervals()->first();

        $preview = $this->actingAs($user)->get(
            route('billing-statements.show', [$managementUnit, $billingInterval])
        );
        $preview->assertStatus(200);
        $preview->assertSee('Heizkosten');
        $preview->assertDontSee('Zinsen');
        $preview->assertDontSee('250');
    }

    /**
     * @return array{0: User, 1: ManagementUnit, 2: BillingInterval, 3: Lease, 4: Lease}
     */
    private function makeTwoTenantSetup(): array
    {
        $this->seed(CostCategorySeeder::class);
        $this->seed(BillingTemplateSeeder::class);

        $user = User::factory()->create();
        $group = $user->groups()->first();

        $managementGroup = ManagementGroup::create(['description' => 'MG', 'user_group_id' => $group->id]);
        $managementUnit = $managementGroup->managementUnits()->create([
            'street' => 'Teststraße 1', 'postal_code' => '12345', 'city' => 'Teststadt',
        ]);

        $billableUnit = $managementUnit->rentalUnits()->create([
            'type' => 'Wohnung', 'size' => 60, 'description' => 'Whg 1', 'is_relevant_for_billing' => true,
        ]);
        $nonBillableUnit = $managementUnit->rentalUnits()->create([
            'type' => 'Garage', 'size' => 20, 'description' => 'Garage 1', 'is_relevant_for_billing' => false,
        ]);

        $tenantA = Tenant::create(['first_name' => 'Max', 'last_name' => 'Mustermann', 'street' => 'A', 'postal_code' => '1', 'city' => 'B']);
        $leaseA = Lease::create(['rental_unit_id' => $billableUnit->id, 'start_date' => '2026-01-01', 'rent_adjustment_type' => 'fixed', 'cold_rent' => 500]);
        $leaseA->tenants()->attach($tenantA->id);

        $tenantB = Tenant::create(['first_name' => 'Erika', 'last_name' => 'Musterfrau', 'street' => 'A', 'postal_code' => '1', 'city' => 'B']);
        $leaseB = Lease::create(['rental_unit_id' => $nonBillableUnit->id, 'start_date' => '2026-01-01', 'rent_adjustment_type' => 'fixed', 'cold_rent' => 100]);
        $leaseB->tenants()->attach($tenantB->id);

        $billingInterval = $managementGroup->billingIntervals()->first();

        return [$user, $managementUnit, $billingInterval, $leaseA, $leaseB];
    }

    public function test_a_lease_that_ended_before_the_billing_interval_started_is_excluded(): void
    {
        [$user, $managementUnit, $billingInterval] = $this->makeTwoTenantSetup();

        $pastTenant = Tenant::create(['first_name' => 'Alt', 'last_name' => 'Ausgezogen', 'street' => 'A', 'postal_code' => '1', 'city' => 'B']);
        $rentalUnit = $managementUnit->rentalUnits()->create(['type' => 'Wohnung', 'size' => 40, 'is_relevant_for_billing' => true]);
        Lease::create([
            'rental_unit_id' => $rentalUnit->id, 'start_date' => '2024-01-01', 'end_date' => '2024-12-31',
            'rent_adjustment_type' => 'fixed', 'cold_rent' => 300,
        ])->tenants()->attach($pastTenant->id);

        $response = $this->actingAs($user)->get(route('billing-statements.show', [$managementUnit, $billingInterval]));

        $response->assertOk();
        $response->assertDontSee('Ausgezogen');
    }

    public function test_a_lease_of_a_non_billable_rental_unit_is_excluded_from_the_statement(): void
    {
        [$user, $managementUnit, $billingInterval] = $this->makeTwoTenantSetup();

        $response = $this->actingAs($user)->get(route('billing-statements.show', [$managementUnit, $billingInterval]));

        $response->assertOk();
        $response->assertSee('Mustermann');
        $response->assertDontSee('Musterfrau');
    }

    public function test_a_single_leases_statement_can_be_previewed_and_downloaded_as_pdf_with_the_correct_filename(): void
    {
        [$user, $managementUnit, $billingInterval, $leaseA] = $this->makeTwoTenantSetup();

        $preview = $this->actingAs($user)->get(route('billing-statements.show-lease', [$managementUnit, $billingInterval, $leaseA]));
        $preview->assertOk();
        $preview->assertSee('Mustermann');
        $preview->assertDontSee('Musterfrau');

        $pdf = $this->actingAs($user)->get(route('billing-statements.pdf-lease', [$managementUnit, $billingInterval, $leaseA]));
        $pdf->assertOk();
        $pdf->assertHeader('content-type', 'application/pdf');
        $this->assertStringContainsString(
            '2026__Betriebskostenabrechnung__Max_Mustermann.pdf',
            $pdf->headers->get('content-disposition')
        );
    }

    public function test_previewing_a_non_billable_leases_statement_is_rejected(): void
    {
        [$user, $managementUnit, $billingInterval, , $leaseB] = $this->makeTwoTenantSetup();

        $response = $this->actingAs($user)->get(route('billing-statements.show-lease', [$managementUnit, $billingInterval, $leaseB]));

        $response->assertNotFound();
    }

    public function test_the_zip_download_contains_one_pdf_per_relevant_lease(): void
    {
        $this->seed(CostCategorySeeder::class);
        $this->seed(BillingTemplateSeeder::class);

        $user = User::factory()->create();
        $group = $user->groups()->first();
        $managementGroup = ManagementGroup::create(['description' => 'MG', 'user_group_id' => $group->id]);
        $managementUnit = $managementGroup->managementUnits()->create([
            'street' => 'Teststraße 1', 'postal_code' => '12345', 'city' => 'Teststadt',
        ]);

        $unit1 = $managementUnit->rentalUnits()->create(['type' => 'Wohnung', 'size' => 60, 'is_relevant_for_billing' => true]);
        $unit2 = $managementUnit->rentalUnits()->create(['type' => 'Wohnung', 'size' => 50, 'is_relevant_for_billing' => true]);

        $tenantA = Tenant::create(['first_name' => 'Max', 'last_name' => 'Mustermann', 'street' => 'A', 'postal_code' => '1', 'city' => 'B']);
        Lease::create(['rental_unit_id' => $unit1->id, 'start_date' => '2026-01-01', 'rent_adjustment_type' => 'fixed', 'cold_rent' => 500])
            ->tenants()->attach($tenantA->id);

        $tenantB = Tenant::create(['first_name' => 'Erika', 'last_name' => 'Musterfrau', 'street' => 'A', 'postal_code' => '1', 'city' => 'B']);
        Lease::create(['rental_unit_id' => $unit2->id, 'start_date' => '2026-01-01', 'rent_adjustment_type' => 'fixed', 'cold_rent' => 400])
            ->tenants()->attach($tenantB->id);

        $billingInterval = $managementGroup->billingIntervals()->first();

        $response = $this->actingAs($user)->get(route('billing-statements.zip', [$managementUnit, $billingInterval]));

        $response->assertOk();
        $response->assertHeader('content-type', 'application/zip');

        $tmpPath = tempnam(sys_get_temp_dir(), 'zip-test-');
        file_put_contents($tmpPath, $response->streamedContent());
        $zip = new \ZipArchive;
        $zip->open($tmpPath);

        $this->assertSame(2, $zip->numFiles);
        $this->assertNotFalse($zip->locateName('2026__Betriebskostenabrechnung__Max_Mustermann.pdf'));
        $this->assertNotFalse($zip->locateName('2026__Betriebskostenabrechnung__Erika_Musterfrau.pdf'));

        $zip->close();
        unlink($tmpPath);
    }

    public function test_a_user_without_access_to_the_management_unit_cannot_preview_a_single_lease(): void
    {
        [, $managementUnit, $billingInterval, $leaseA] = $this->makeTwoTenantSetup();
        $outsider = User::factory()->create();

        $response = $this->actingAs($outsider)->get(route('billing-statements.show-lease', [$managementUnit, $billingInterval, $leaseA]));

        $response->assertForbidden();
    }

    public function test_a_multi_tenant_leases_pdf_filename_replaces_spaces_with_underscores_but_keeps_the_comma(): void
    {
        [$user, $managementUnit, $billingInterval, $leaseA] = $this->makeTwoTenantSetup();
        $secondTenant = Tenant::create(['first_name' => 'Paul', 'last_name' => 'Beispiel', 'street' => 'A', 'postal_code' => '1', 'city' => 'B']);
        $leaseA->tenants()->attach($secondTenant->id);

        $pdf = $this->actingAs($user)->get(route('billing-statements.pdf-lease', [$managementUnit, $billingInterval, $leaseA]));

        $pdf->assertOk();
        $this->assertStringContainsString(
            '2026__Betriebskostenabrechnung__Max_Mustermann,_Paul_Beispiel.pdf',
            $pdf->headers->get('content-disposition')
        );
    }

    public function test_the_new_template_variables_expose_area_person_months_and_per_lease_rent_details(): void
    {
        $this->seed(CostCategorySeeder::class);

        $user = User::factory()->create();
        $group = $user->groups()->first();
        $managementGroup = ManagementGroup::create(['description' => 'MG', 'user_group_id' => $group->id]);

        $managementUnit = $managementGroup->managementUnits()->create([
            'street' => 'Teststraße 1', 'postal_code' => '12345', 'city' => 'Teststadt',
        ]);
        $billableUnit = $managementUnit->rentalUnits()->create(['type' => 'Wohnung', 'size' => 60, 'is_relevant_for_billing' => true]);
        $garage = $managementUnit->rentalUnits()->create(['type' => 'Garage', 'size' => 15, 'is_relevant_for_billing' => false]);

        $otherManagementUnit = $managementGroup->managementUnits()->create([
            'street' => 'Teststraße 2', 'postal_code' => '12345', 'city' => 'Teststadt',
        ]);
        $otherManagementUnit->rentalUnits()->create(['type' => 'Wohnung', 'size' => 40, 'is_relevant_for_billing' => true]);
        $otherLease = Lease::create([
            'rental_unit_id' => $otherManagementUnit->rentalUnits()->first()->id, 'start_date' => '2026-01-01',
            'rent_adjustment_type' => 'fixed', 'cold_rent' => 300,
        ]);
        $otherLease->currentLeaseInterval()->update(['occupant_count' => 3]);

        $tenant = Tenant::create(['first_name' => 'Max', 'last_name' => 'Mustermann', 'street' => 'A', 'postal_code' => '1', 'city' => 'B']);

        $lease = Lease::create(['rental_unit_id' => $billableUnit->id, 'start_date' => '2026-01-01', 'rent_adjustment_type' => 'fixed', 'cold_rent' => 500]);
        $lease->tenants()->attach($tenant->id);
        $lease->currentLeaseInterval()->update(['occupant_count' => 2, 'utility_advance_payment' => 100]);

        $garageLease = Lease::create(['rental_unit_id' => $garage->id, 'start_date' => '2026-01-01', 'rent_adjustment_type' => 'fixed', 'cold_rent' => 50]);
        $garageLease->tenants()->attach($tenant->id);

        $template = BillingTemplate::create([
            'name' => 'Variablen-Test',
            'is_default' => true,
            'content' => '<html><body>'
                .'Flaeche-VE: {{ $managementUnitTotalArea }} '
                .'Flaeche-VG: {{ $managementGroupTotalArea }} '
                .'Personenmonate-VE: {{ $managementUnitPersonMonths }} '
                .'Personenmonate-VG: {{ $managementGroupPersonMonths }} '
                .'Intervalltage: {{ $billingIntervalDays }} '
                .'@foreach ($leases as $entry)'
                .'AktuelleKaltmiete: {{ $entry["currentColdRent"] }} '
                .'AktuelleNK: {{ $entry["currentUtilityAdvance"] }} '
                .'GesamtNK: {{ $entry["totalUtilityAdvance"] }} '
                .'@foreach ($entry["otherRents"] as $other)'
                .'AndereMiete: {{ $other["coldRent"] }} '
                .'@endforeach '
                .'@endforeach'
                .'</body></html>',
        ]);

        $billingInterval = $managementGroup->billingIntervals()->first();

        $response = $this->actingAs($user)->get(route('billing-statements.show-lease', [$managementUnit, $billingInterval, $lease]));

        $response->assertOk();
        $response->assertSee('Flaeche-VE: 75', false);
        $response->assertSee('Flaeche-VG: 115', false);
        $response->assertSee('Personenmonate-VE: 36', false);
        $response->assertSee('Personenmonate-VG: 72', false);
        $response->assertSee('Intervalltage: 365', false);
        $response->assertSee('AktuelleKaltmiete: 500', false);
        $response->assertSee('AktuelleNK: 100', false);
        $response->assertSee('GesamtNK: 1200', false);
        $response->assertSee('AndereMiete: 50', false);
    }

    /**
     * End-to-end check that BillingCostAllocationService's 4-stage Berechnung is
     * actually wired into the real statement pipeline: with exactly one Mietvertrag
     * in the whole Verwaltungsgruppe, every ratio-based factor collapses to 1
     * (Mieter = Gruppe = Einheit), making the expected totals trivial to verify by
     * hand while still exercising all 4 Stufen through real HTTP + real Cost rows.
     */
    public function test_allocated_costs_and_balance_are_available_on_the_rendered_statement(): void
    {
        $user = User::factory()->create();
        $group = $user->groups()->first();
        $managementGroup = ManagementGroup::create(['description' => 'MG', 'user_group_id' => $group->id]);
        $managementUnit = $managementGroup->managementUnits()->create([
            'street' => 'Teststraße 1', 'postal_code' => '12345', 'city' => 'Teststadt',
        ]);
        $rentalUnit = $managementUnit->rentalUnits()->create(['type' => 'Wohnung', 'size' => 100, 'is_relevant_for_billing' => true]);

        $tenant = Tenant::create(['first_name' => 'Max', 'last_name' => 'Mustermann', 'street' => 'A', 'postal_code' => '1', 'city' => 'B']);
        $lease = Lease::create(['rental_unit_id' => $rentalUnit->id, 'start_date' => '2026-01-01', 'rent_adjustment_type' => 'fixed', 'cold_rent' => 500]);
        $lease->tenants()->attach($tenant->id);
        $lease->currentLeaseInterval()->update(['occupant_count' => 2, 'utility_advance_payment' => 100]);

        $billingInterval = $managementGroup->billingIntervals()->first();

        $personCategory = CostCategory::create(['name' => 'Heizkosten', 'is_relevant_for_billing' => true]);
        $managementGroup->costCategories()->attach($personCategory->id, ['allocation_key' => 'person_count']);
        $areaCategory = CostCategory::create(['name' => 'Wasserversorgung', 'is_relevant_for_billing' => true]);
        $managementGroup->costCategories()->attach($areaCategory->id, ['allocation_key' => 'area']);
        $unitCategory = CostCategory::create(['name' => 'Reinigung', 'is_relevant_for_billing' => true]);
        $managementGroup->costCategories()->attach($unitCategory->id, ['allocation_key' => 'area']);
        $individualCategory = CostCategory::create(['name' => 'Individuell', 'is_relevant_for_billing' => true]);
        $managementGroup->costCategories()->attach($individualCategory->id, ['allocation_key' => 'individual']);

        $managementGroup->costs()->create([
            'billing_interval_id' => $billingInterval->id, 'cost_category_id' => $personCategory->id,
            'amount' => 240, 'period_date' => '2026-06-01',
        ]);
        $managementUnit->costs()->create([
            'billing_interval_id' => $billingInterval->id, 'cost_category_id' => $areaCategory->id,
            'amount' => 100, 'period_date' => '2026-06-01',
        ]);
        $rentalUnit->costs()->create([
            'billing_interval_id' => $billingInterval->id, 'cost_category_id' => $unitCategory->id,
            'amount' => 50, 'period_date' => '2026-06-01',
        ]);
        $lease->costs()->create([
            'billing_interval_id' => $billingInterval->id, 'cost_category_id' => $individualCategory->id,
            'amount' => 30, 'period_date' => '2026-06-01',
        ]);

        BillingTemplate::create([
            'name' => 'Berechnung-Test',
            'is_default' => true,
            'content' => '<html><body>'
                .'@foreach ($leases as $entry)'
                .'VG: {{ $entry["allocatedCosts"]["byStage"]["managementGroup"]["total"] }} '
                .'VE: {{ $entry["allocatedCosts"]["byStage"]["managementUnit"]["total"] }} '
                .'ME: {{ $entry["allocatedCosts"]["byStage"]["rentalUnit"]["total"] }} '
                .'MV: {{ $entry["allocatedCosts"]["byStage"]["lease"]["total"] }} '
                .'Gesamt: {{ $entry["allocatedCosts"]["total"] }} '
                .'Saldo: {{ $entry["balance"] }} '
                .'@foreach ($entry["allocatedCosts"]["byStage"]["managementGroup"]["byCategory"] as $kat => $betrag)'
                .'VG-Kat: {{ $kat }}={{ $betrag }} '
                .'@endforeach '
                .'@endforeach'
                .'</body></html>',
        ]);

        $response = $this->actingAs($user)->get(route('billing-statements.show', [$managementUnit, $billingInterval]));

        $response->assertOk();
        $response->assertSee('VG: 240', false);
        $response->assertSee('VE: 100', false);
        $response->assertSee('ME: 50', false);
        $response->assertSee('MV: 30', false);
        $response->assertSee('Gesamt: 420', false);
        // Gesamtkosten 420 - Vorauszahlungen 1200 (12 * 100) = -780 (Rückerstattung).
        $response->assertSee('Saldo: -780', false);
        // Die Verwaltungsgruppen-Stufe schlüsselt ihren Anteil zusätzlich je Kategorie auf.
        $response->assertSee('VG-Kat: Heizkosten=240', false);
    }

    /**
     * End-to-end check that the suggested new Betriebskostenvorauszahlung
     * (BillingCostAllocationService::suggestedMonthlyUtilityAdvance()) is wired into
     * the real statement pipeline and available to a template.
     */
    public function test_suggested_utility_advance_is_available_on_the_rendered_statement(): void
    {
        $user = User::factory()->create();
        $group = $user->groups()->first();
        $managementGroup = ManagementGroup::create(['description' => 'MG', 'user_group_id' => $group->id]);
        $managementUnit = $managementGroup->managementUnits()->create([
            'street' => 'Teststraße 1', 'postal_code' => '12345', 'city' => 'Teststadt',
        ]);
        $rentalUnit = $managementUnit->rentalUnits()->create(['type' => 'Wohnung', 'size' => 100, 'is_relevant_for_billing' => true]);

        $tenant = Tenant::create(['first_name' => 'Max', 'last_name' => 'Mustermann', 'street' => 'A', 'postal_code' => '1', 'city' => 'B']);
        $lease = Lease::create(['rental_unit_id' => $rentalUnit->id, 'start_date' => '2026-01-01', 'rent_adjustment_type' => 'fixed', 'cold_rent' => 500]);
        $lease->tenants()->attach($tenant->id);
        $lease->currentLeaseInterval()->update(['occupant_count' => 2, 'utility_advance_payment' => 100]);

        $billingInterval = $managementGroup->billingIntervals()->first();

        $category = CostCategory::create(['name' => 'Heizkosten', 'is_relevant_for_billing' => true]);
        $managementGroup->costCategories()->attach($category->id, ['allocation_key' => 'area']);

        // Gesamtkosten 420 € / 12 Monate / 100 m² = 0,35 €/m²/Monat -> aufgerundet 0,40
        // €/m² -> * 100 m² = 40,00 € vorgeschlagene neue monatliche Vorauszahlung.
        $managementGroup->costs()->create([
            'billing_interval_id' => $billingInterval->id, 'cost_category_id' => $category->id,
            'amount' => 420, 'period_date' => '2026-06-01',
        ]);

        BillingTemplate::create([
            'name' => 'Vorschlag-Test',
            'is_default' => true,
            'content' => '<html><body>'
                .'Monate: {{ $billingIntervalMonths }} '
                .'@foreach ($leases as $entry)'
                .'Vorschlag: {{ $entry["suggestedUtilityAdvance"] }} '
                .'@endforeach'
                .'</body></html>',
        ]);

        $response = $this->actingAs($user)->get(route('billing-statements.show', [$managementUnit, $billingInterval]));

        $response->assertOk();
        $response->assertSee('Monate: 12', false);
        $response->assertSee('Vorschlag: 40', false);
    }

    /**
     * Konzept.md "##### Berechnung neue Vorauszahlung" Regel b): ein erst seit 2
     * Monaten laufender Mietvertrag bekommt keinen neuen Vorschlag – die bisherige
     * Vorauszahlung bleibt, auch wenn der rohe Vorschlag stark abweichen würde.
     */
    public function test_suggested_utility_advance_keeps_the_old_value_for_a_lease_that_is_too_new(): void
    {
        $user = User::factory()->create();
        $group = $user->groups()->first();
        $managementGroup = ManagementGroup::create(['description' => 'MG', 'user_group_id' => $group->id]);
        $managementUnit = $managementGroup->managementUnits()->create([
            'street' => 'Teststraße 1', 'postal_code' => '12345', 'city' => 'Teststadt',
        ]);
        $rentalUnit = $managementUnit->rentalUnits()->create(['type' => 'Wohnung', 'size' => 100, 'is_relevant_for_billing' => true]);

        $billingInterval = $managementGroup->billingIntervals()->first();
        $billingInterval->update(['start_date' => '2026-01-01', 'end_date' => '2026-12-31']);

        $tenant = Tenant::create(['first_name' => 'Max', 'last_name' => 'Mustermann', 'street' => 'A', 'postal_code' => '1', 'city' => 'B']);
        // Erst seit 2 Monaten vor Intervallende eingezogen.
        $lease = Lease::create(['rental_unit_id' => $rentalUnit->id, 'start_date' => '2026-11-01', 'rent_adjustment_type' => 'fixed', 'cold_rent' => 500]);
        $lease->tenants()->attach($tenant->id);
        $lease->currentLeaseInterval()->update(['occupant_count' => 2, 'utility_advance_payment' => 100]);

        $category = CostCategory::create(['name' => 'Heizkosten', 'is_relevant_for_billing' => true]);
        $managementGroup->costCategories()->attach($category->id, ['allocation_key' => 'area']);
        $managementGroup->costs()->create([
            'billing_interval_id' => $billingInterval->id, 'cost_category_id' => $category->id,
            'amount' => 420, 'period_date' => '2026-06-01',
        ]);

        BillingTemplate::create([
            'name' => 'Vorschlag-Neu-Test',
            'is_default' => true,
            'content' => '<html><body>@foreach ($leases as $entry)Vorschlag: {{ $entry["suggestedUtilityAdvance"] }} @endforeach</body></html>',
        ]);

        $response = $this->actingAs($user)->get(route('billing-statements.show', [$managementUnit, $billingInterval]));

        $response->assertOk();
        // Die bisherige Vorauszahlung (100) bleibt, statt eines neu berechneten Werts.
        $response->assertSee('Vorschlag: 100', false);
    }

    /**
     * Konzept.md "#### Template": "alle relevanten Buchungen mit (Buchungsdatum, Betrag,
     * Empfänger und Beschreibung) gruppiert nach Zuordnungsebene und danach nach
     * Kategorie. Wenn eine Zuordnungsebene keine Einträge hat wird sie weggelassen.
     * Ebenso, wenn eine Kategorie keine Einträge hat."
     */
    public function test_bookings_by_level_variable_groups_bookings_by_assignment_level_and_category(): void
    {
        $user = User::factory()->create();
        $group = $user->groups()->first();
        $managementGroup = ManagementGroup::create(['description' => 'MG', 'user_group_id' => $group->id]);
        $managementUnit = $managementGroup->managementUnits()->create([
            'street' => 'Teststraße 1', 'postal_code' => '12345', 'city' => 'Teststadt',
        ]);
        $rentalUnit = $managementUnit->rentalUnits()->create(['type' => 'Wohnung', 'size' => 100, 'is_relevant_for_billing' => true]);

        $tenant = Tenant::create(['first_name' => 'Max', 'last_name' => 'Mustermann', 'street' => 'A', 'postal_code' => '1', 'city' => 'B']);
        $lease = Lease::create(['rental_unit_id' => $rentalUnit->id, 'start_date' => '2026-01-01', 'rent_adjustment_type' => 'fixed', 'cold_rent' => 500]);
        $lease->tenants()->attach($tenant->id);

        $billingInterval = $managementGroup->billingIntervals()->first();
        $issuer = InvoiceIssuer::create(['name' => 'Stadtwerke', 'street' => 'A', 'postal_code' => '1', 'city' => 'B']);

        $heizkosten = CostCategory::create(['name' => 'Heizkosten', 'is_relevant_for_billing' => true]);
        $managementGroup->costCategories()->attach($heizkosten->id, ['allocation_key' => 'area']);
        $zinsen = CostCategory::create(['name' => 'Zinsen', 'is_relevant_for_billing' => false]);
        $managementGroup->costCategories()->attach($zinsen->id, ['allocation_key' => 'area']);

        $bookedInvoice = Invoice::create([
            'invoice_issuer_id' => $issuer->id, 'amount' => 61, 'invoice_date' => '2026-01-15',
            'description' => 'Strom Haus', 'periodicity' => 'once',
        ]);
        $managementUnit->costs()->create([
            'invoice_id' => $bookedInvoice->id, 'billing_interval_id' => $billingInterval->id,
            'cost_category_id' => $heizkosten->id, 'amount' => 61, 'period_date' => '2026-01-15',
            'booking_date' => '2026-01-15',
        ]);

        // Noch offen (kein booking_date) – darf in der Buchungsübersicht nicht auftauchen,
        // im Gegensatz zu costsByCategory/costsByMonth (Prognose).
        $managementUnit->costs()->create([
            'billing_interval_id' => $billingInterval->id, 'cost_category_id' => $heizkosten->id,
            'amount' => 61, 'period_date' => '2026-02-15',
        ]);

        // Nicht abrechnungsrelevant – darf ebenfalls nicht auftauchen.
        $managementGroup->costs()->create([
            'billing_interval_id' => $billingInterval->id, 'cost_category_id' => $zinsen->id,
            'amount' => 240, 'period_date' => '2026-06-01', 'booking_date' => '2026-06-01',
        ]);

        BillingTemplate::create([
            'name' => 'Buchungen-Test',
            'is_default' => true,
            'content' => '<html><body>'
                .'@foreach ($bookingsByLevel as $level => $categories)'
                .'Ebene: {{ $level }} '
                .'@foreach ($categories as $kategorie => $buchungen)'
                .'Kategorie: {{ $kategorie }} '
                .'@foreach ($buchungen as $buchung)'
                .'Buchung: {{ $buchung["date"]->format("d.m.Y") }}|{{ $buchung["issuer"] }}|{{ $buchung["description"] }}|{{ $buchung["amount"] }} '
                .'@endforeach @endforeach @endforeach'
                .'</body></html>',
        ]);

        $response = $this->actingAs($user)->get(route('billing-statements.show', [$managementUnit, $billingInterval]));

        $response->assertOk();
        $response->assertSee('Ebene: Verwaltungseinheit', false);
        $response->assertSee('Kategorie: Heizkosten', false);
        $response->assertSee('Buchung: 15.01.2026|Stadtwerke|Strom Haus|61', false);
        // Weder die offene Prognose-Zeile noch die nicht abrechnungsrelevante Zinsen-
        // Buchung dürfen auftauchen; entsprechend fehlt auch die ganze "Verwaltungsgruppe"-
        // Ebene (sie hätte sonst nur die Zinsen-Buchung enthalten).
        $response->assertDontSee('Ebene: Verwaltungsgruppe', false);
        $response->assertDontSee('Zinsen', false);
    }

    /**
     * Individuelle (Mietvertrags-)Buchungen dürfen im Einzel-Statement eines Mieters nur
     * dessen EIGENE enthalten – niemals die eines anderen Mietvertrags derselben
     * Verwaltungseinheit (Datenschutz: sonst sähe ein Mieter die individuellen Kosten
     * eines anderen Mieters in seinem eigenen PDF).
     */
    public function test_bookings_by_level_on_a_single_leases_statement_only_contains_that_leases_own_individual_bookings(): void
    {
        $user = User::factory()->create();
        $group = $user->groups()->first();
        $managementGroup = ManagementGroup::create(['description' => 'MG', 'user_group_id' => $group->id]);
        $managementUnit = $managementGroup->managementUnits()->create([
            'street' => 'Teststraße 1', 'postal_code' => '12345', 'city' => 'Teststadt',
        ]);
        $unitA = $managementUnit->rentalUnits()->create(['type' => 'Wohnung', 'size' => 60, 'is_relevant_for_billing' => true]);
        $unitB = $managementUnit->rentalUnits()->create(['type' => 'Wohnung', 'size' => 50, 'is_relevant_for_billing' => true]);

        $tenantA = Tenant::create(['first_name' => 'Max', 'last_name' => 'Mustermann', 'street' => 'A', 'postal_code' => '1', 'city' => 'B']);
        $leaseA = Lease::create(['rental_unit_id' => $unitA->id, 'start_date' => '2026-01-01', 'rent_adjustment_type' => 'fixed', 'cold_rent' => 500]);
        $leaseA->tenants()->attach($tenantA->id);

        $tenantB = Tenant::create(['first_name' => 'Erika', 'last_name' => 'Musterfrau', 'street' => 'A', 'postal_code' => '1', 'city' => 'B']);
        $leaseB = Lease::create(['rental_unit_id' => $unitB->id, 'start_date' => '2026-01-01', 'rent_adjustment_type' => 'fixed', 'cold_rent' => 400]);
        $leaseB->tenants()->attach($tenantB->id);

        $billingInterval = $managementGroup->billingIntervals()->first();
        $issuer = InvoiceIssuer::create(['name' => 'Dienstleister', 'street' => 'A', 'postal_code' => '1', 'city' => 'B']);
        $individuell = CostCategory::create(['name' => 'Individuell', 'is_relevant_for_billing' => true]);
        $managementGroup->costCategories()->attach($individuell->id, ['allocation_key' => 'individual']);

        $invoiceA = Invoice::create(['invoice_issuer_id' => $issuer->id, 'amount' => 30, 'invoice_date' => '2026-01-15', 'description' => 'Für Mustermann', 'periodicity' => 'once']);
        $leaseA->costs()->create([
            'invoice_id' => $invoiceA->id, 'billing_interval_id' => $billingInterval->id, 'cost_category_id' => $individuell->id,
            'amount' => 30, 'period_date' => '2026-01-15', 'booking_date' => '2026-01-15',
        ]);

        $invoiceB = Invoice::create(['invoice_issuer_id' => $issuer->id, 'amount' => 45, 'invoice_date' => '2026-01-20', 'description' => 'Für Musterfrau', 'periodicity' => 'once']);
        $leaseB->costs()->create([
            'invoice_id' => $invoiceB->id, 'billing_interval_id' => $billingInterval->id, 'cost_category_id' => $individuell->id,
            'amount' => 45, 'period_date' => '2026-01-20', 'booking_date' => '2026-01-20',
        ]);

        BillingTemplate::create([
            'name' => 'Datenschutz-Test',
            'is_default' => true,
            'content' => '<html><body>'
                .'@foreach ($bookingsByLevel as $level => $categories)'
                .'@foreach ($categories as $kategorie => $buchungen)'
                .'@foreach ($buchungen as $buchung)'
                .'Buchung: {{ $buchung["description"] }} '
                .'@endforeach @endforeach @endforeach'
                .'</body></html>',
        ]);

        $response = $this->actingAs($user)->get(route('billing-statements.show-lease', [$managementUnit, $billingInterval, $leaseA]));

        $response->assertOk();
        $response->assertSee('Buchung: Für Mustermann', false);
        $response->assertDontSee('Für Musterfrau', false);
    }

    public function test_the_effective_landlord_is_available_on_the_rendered_statement(): void
    {
        $user = User::factory()->create();
        $group = $user->groups()->first();
        $managementGroup = ManagementGroup::create(['description' => 'MG', 'user_group_id' => $group->id]);
        $managementUnit = $managementGroup->managementUnits()->create([
            'street' => 'Teststraße 1', 'postal_code' => '12345', 'city' => 'Teststadt',
        ]);
        $rentalUnit = $managementUnit->rentalUnits()->create(['type' => 'Wohnung', 'size' => 60, 'is_relevant_for_billing' => true]);
        $tenant = Tenant::create(['first_name' => 'Max', 'last_name' => 'Mustermann', 'street' => 'A', 'postal_code' => '1', 'city' => 'B']);
        $lease = Lease::create(['rental_unit_id' => $rentalUnit->id, 'start_date' => '2026-01-01', 'rent_adjustment_type' => 'fixed', 'cold_rent' => 500]);
        $lease->tenants()->attach($tenant->id);

        Landlord::create(['user_group_id' => $group->id, 'name' => 'Hausverwaltung Mustermann']);

        BillingTemplate::create([
            'name' => 'Vermieter-Test',
            'is_default' => true,
            'content' => '<html><body>Vermieter: {{ $landlord?->name }}</body></html>',
        ]);

        $billingInterval = $managementGroup->billingIntervals()->first();

        $response = $this->actingAs($user)->get(route('billing-statements.show', [$managementUnit, $billingInterval]));

        $response->assertOk();
        $response->assertSee('Vermieter: Hausverwaltung Mustermann', false);
    }

    /**
     * Konzept.md "##### Leerstände": "Abrechnungsrelevante, aber leerstehende
     * Mieteinheiten müssen berücksichtigt werden. Berechnet werden diese mit einem 0
     * Personenhaushalt. Ansonsten werden sie wie ein Mieter gehandhabt." und "Zu
     * diesem Leerstand müssen auch Forderungen ... verbucht werden." – eine
     * leerstehende, abrechnungsrelevante Mieteinheit erscheint als eigener Eintrag
     * direkt in der kombinierten Betriebskostenabrechnung, mit ihrem eigenen
     * Vermieteranteil und ihren verbuchten Forderungen.
     */
    public function test_a_fully_vacant_rental_unit_appears_with_its_own_share_and_claims_in_the_combined_statement(): void
    {
        $this->seed(CostCategorySeeder::class);
        $this->seed(BillingTemplateSeeder::class);

        $user = User::factory()->create();
        $group = $user->groups()->first();
        $managementGroup = ManagementGroup::create(['description' => 'MG', 'user_group_id' => $group->id]);
        $managementUnit = $managementGroup->managementUnits()->create([
            'street' => 'Teststraße 1', 'postal_code' => '12345', 'city' => 'Teststadt',
        ]);
        // Mieteinheit steht das gesamte Abrechnungsintervall (2026) leer – keine Leases.
        $rentalUnit = $managementUnit->rentalUnits()->create(['type' => 'Wohnung', 'size' => 50, 'is_relevant_for_billing' => true]);

        $billingInterval = $managementGroup->billingIntervals()->first();

        $category = CostCategory::where('name', 'Heizkosten')->first();
        $managementGroup->costCategories()->syncWithoutDetaching([$category->id => ['allocation_key' => 'area']]);
        $managementUnit->costs()->create([
            'billing_interval_id' => $billingInterval->id, 'cost_category_id' => $category->id,
            'amount' => 120, 'period_date' => '2026-06-01',
        ]);

        $rentalUnit->settlementClaims()->create([
            'billing_interval_id' => $billingInterval->id, 'date' => '2026-07-01', 'amount' => 42,
            'notes' => 'Nachforderung Abrechnungsgesellschaft',
        ]);

        $preview = $this->actingAs($user)->get(route('billing-statements.show', [$managementUnit, $billingInterval]));
        $preview->assertOk();
        $preview->assertSee('Leerstand');
        $preview->assertSee('01.01.2026');
        $preview->assertSee('31.12.2026');
        $preview->assertSee('Heizkosten');
        $preview->assertSee('Nachforderung Abrechnungsgesellschaft');
        // Kein Anschreiben ("Sehr geehrte(r)") für eine Mieteinheit ohne Mieter.
        $preview->assertDontSee('Sehr geehrte(r) Leerstand');
        // Vermieteranteil 120 € (einzige Mieteinheit der Verwaltungseinheit, das ganze
        // Jahr leer -> voller Stufe-2-Anteil) + 42 € Forderung = 162 € "Vom Vermieter
        // zu tragen" – die Forderung verändert die Summe direkt (Nutzerentscheidung).
        $preview->assertSee('Vom Vermieter zu tragen');
        $preview->assertSee('162,00');

        $pdf = $this->actingAs($user)->get(route('billing-statements.pdf', [$managementUnit, $billingInterval]));
        $pdf->assertOk();
        $pdf->assertHeader('content-type', 'application/pdf');
    }

    /**
     * Eine Einzelabrechnung (showLease()/pdfLease()) oder das ZIP (ein PDF je echtem
     * Mieter) enthalten dagegen NIE einen Leerstands-Eintrag – dort gibt es je Datei
     * genau einen realen Empfänger.
     */
    public function test_vacancy_does_not_appear_on_a_single_leases_statement_or_in_the_zip(): void
    {
        [$user, $managementUnit, $billingInterval, $leaseA] = $this->makeTwoTenantSetup();
        // makeTwoTenantSetup() lässt bereits eine zweite (nicht abrechnungsrelevante)
        // Mieteinheit unbelegt; hier zusätzlich eine dritte, abrechnungsrelevante und
        // tatsächlich leerstehende Mieteinheit anlegen.
        $managementUnit->rentalUnits()->create(['type' => 'Wohnung', 'size' => 30, 'is_relevant_for_billing' => true]);

        $single = $this->actingAs($user)->get(route('billing-statements.show-lease', [$managementUnit, $billingInterval, $leaseA]));
        $single->assertOk();
        $single->assertDontSee('Leerstand');

        $zip = $this->actingAs($user)->get(route('billing-statements.zip', [$managementUnit, $billingInterval]));
        $zip->assertOk();

        $tmpPath = tempnam(sys_get_temp_dir(), 'zip-test-');
        file_put_contents($tmpPath, $zip->streamedContent());
        $archive = new \ZipArchive;
        $archive->open($tmpPath);
        // makeTwoTenantSetup() hat nur EINEN abrechnungsrelevanten, echten Mieter
        // (leaseB sitzt auf einer nicht abrechnungsrelevanten Einheit) – keine
        // zusätzliche Datei für den Leerstand der dritten Mieteinheit.
        $this->assertSame(1, $archive->numFiles);
        $archive->close();
        unlink($tmpPath);
    }
}
