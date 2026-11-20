<?php

namespace Tests\Feature\Api;

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
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Api\Concerns\CreatesApiFixtures;
use Tests\TestCase;

// KI-generiert
class InvoiceControllerTest extends TestCase
{
    use CreatesApiFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CostCategorySeeder::class);
    }

    /**
     * @return array<string, mixed>
     */
    private function createPayload(User $user, ManagementGroup $managementGroup, array $overrides = []): array
    {
        return [
            'invoice_issuer_id' => $this->makeIssuer($user)->id,
            'invoice_date' => '2026-01-15',
            'periodicity' => 'once',
            'amount' => 120,
            'costable' => "management_group:{$managementGroup->id}",
            'billing_interval_id' => $managementGroup->billingIntervals()->first()->id,
            'cost_category_id' => CostCategory::first()->id,
            ...$overrides,
        ];
    }

    private function makeInvoice(User $user, ManagementGroup $managementGroup, array $attributes = []): Invoice
    {
        return Invoice::create([
            'invoice_issuer_id' => $this->makeIssuer($user)->id, 'amount' => 100,
            'invoice_date' => '2026-01-01', 'periodicity' => 'once',
            'costable_type' => $managementGroup->getMorphClass(), 'costable_id' => $managementGroup->id,
            ...$attributes,
        ]);
    }

    public function test_the_index_hides_fully_booked_invoices_unless_requested(): void
    {
        $user = $this->apiUser();
        $managementGroup = $this->makeManagementGroup($user);
        $open = $this->makeInvoice($user, $managementGroup);
        $booked = $this->makeInvoice($user, $managementGroup);
        $booked->costs()->create([
            'costable_type' => $managementGroup->getMorphClass(), 'costable_id' => $managementGroup->id,
            'billing_interval_id' => $managementGroup->billingIntervals()->first()->id,
            'cost_category_id' => CostCategory::first()->id, 'amount' => 100,
            'period_date' => '2026-01-01', 'booking_date' => '2026-01-05',
        ]);
        // Rechnungssteller und Verwaltungsgruppe desselben Fremden (GroupOwnership).
        $stranger = User::factory()->create();
        $this->makeInvoice($stranger, $this->makeManagementGroup($stranger));

        $this->getJson(route('api.invoices.index'))->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $open->id);
        $this->getJson(route('api.invoices.index', ['show_completed' => 1]))->assertOk()->assertJsonCount(2, 'data');
    }

    public function test_a_one_time_invoice_is_created_with_its_cost_row(): void
    {
        $user = $this->apiUser();
        $managementGroup = $this->makeManagementGroup($user);

        $response = $this->postJson(route('api.invoices.store'), $this->createPayload($user, $managementGroup));

        $response->assertCreated()->assertJsonPath('costable_type', 'management_group');
        $this->assertSame(1, Invoice::find($response->json('id'))->costs()->count());
    }

    public function test_a_manual_invoice_sums_its_occurrences_as_the_amount(): void
    {
        $user = $this->apiUser();
        $managementGroup = $this->makeManagementGroup($user);

        $response = $this->postJson(route('api.invoices.store'), $this->createPayload($user, $managementGroup, [
            'periodicity' => 'manual', 'amount' => null,
            'occurrences' => [
                ['occurrence_date' => '2026-01-15', 'amount' => 40],
                ['occurrence_date' => '2026-02-15', 'amount' => 60],
            ],
        ]));

        $response->assertCreated();
        $invoice = Invoice::find($response->json('id'));
        $this->assertEquals(100, $invoice->amount);
        $this->assertSame(2, $invoice->occurrences()->count());
    }

    public function test_a_manual_invoice_without_occurrences_is_rejected(): void
    {
        $user = $this->apiUser();
        $managementGroup = $this->makeManagementGroup($user);

        $this->postJson(route('api.invoices.store'), $this->createPayload($user, $managementGroup, [
            'periodicity' => 'manual', 'occurrences' => [],
        ]))->assertUnprocessable()->assertJsonValidationErrors('occurrences');
    }

    public function test_an_invoice_with_an_uploaded_receipt_stores_the_file(): void
    {
        Storage::fake(FileStorage::diskName());
        $user = $this->apiUser();
        $managementGroup = $this->makeManagementGroup($user);

        $response = $this->post(route('api.invoices.store'), $this->createPayload($user, $managementGroup, [
            'file' => UploadedFile::fake()->create('beleg.pdf', 10, 'application/pdf'),
        ]), ['Accept' => 'application/json']);

        $response->assertCreated()->assertJsonPath('file_original_name', 'beleg.pdf');
        FileStorage::disk()->assertExists(Invoice::first()->file_path);
    }

    public function test_an_invoice_issuer_of_a_foreign_group_is_forbidden(): void
    {
        $user = $this->apiUser();
        $managementGroup = $this->makeManagementGroup($user);

        $this->postJson(route('api.invoices.store'), $this->createPayload($user, $managementGroup, [
            'invoice_issuer_id' => $this->makeIssuer(User::factory()->create())->id,
        ]))->assertForbidden();
    }

    public function test_an_unknown_costable_type_is_not_found(): void
    {
        $user = $this->apiUser();
        $managementGroup = $this->makeManagementGroup($user);

        $this->postJson(route('api.invoices.store'), $this->createPayload($user, $managementGroup, [
            'costable' => 'unbekannt:1',
        ]))->assertNotFound();
    }

    public function test_a_foreign_costable_is_forbidden(): void
    {
        $user = $this->apiUser();
        $managementGroup = $this->makeManagementGroup($user);
        $foreignUnit = $this->makeManagementUnit(User::factory()->create());

        $this->postJson(route('api.invoices.store'), $this->createPayload($user, $managementGroup, [
            'costable' => "management_unit:{$foreignUnit->id}",
        ]))->assertForbidden();
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function costableTypes(): array
    {
        return ['Verwaltungseinheit' => ['management_unit'], 'Mieteinheit' => ['rental_unit'], 'Mietvertrag' => ['lease']];
    }

    #[DataProvider('costableTypes')]
    public function test_an_invoice_can_be_assigned_to_every_costable_level(string $type): void
    {
        $user = $this->apiUser();
        $lease = $this->makeLease($user);
        $costable = match ($type) {
            'management_unit' => $lease->rentalUnit->managementUnit,
            'rental_unit' => $lease->rentalUnit,
            'lease' => $lease,
        };
        $managementGroup = $lease->rentalUnit->managementUnit->managementGroup;

        $response = $this->postJson(route('api.invoices.store'), $this->createPayload($user, $managementGroup, [
            'costable' => "{$type}:{$costable->id}",
        ]));

        $response->assertCreated()->assertJsonPath('costable_type', $type);
    }

    public function test_an_invoice_can_be_shown_with_its_costs(): void
    {
        $user = $this->apiUser();
        $managementGroup = $this->makeManagementGroup($user);
        $id = $this->postJson(route('api.invoices.store'), $this->createPayload($user, $managementGroup))->json('id');

        $this->getJson(route('api.invoices.show', $id))->assertOk()->assertJsonCount(1, 'costs');
    }

    public function test_a_foreign_invoice_cannot_be_shown(): void
    {
        $owner = User::factory()->create();
        $invoice = $this->makeInvoice($owner, $this->makeManagementGroup($owner));
        $this->apiUser();

        $this->getJson(route('api.invoices.show', $invoice))->assertForbidden();
    }

    public function test_an_invoice_can_be_updated_and_its_receipt_replaced(): void
    {
        Storage::fake(FileStorage::diskName());
        $user = $this->apiUser();
        $managementGroup = $this->makeManagementGroup($user);
        FileStorage::disk()->put('invoices/alt.pdf', 'alt');
        $invoice = $this->makeInvoice($user, $managementGroup, ['file_path' => 'invoices/alt.pdf']);

        $response = $this->put(route('api.invoices.update', $invoice), [
            'invoice_issuer_id' => $invoice->invoice_issuer_id, 'amount' => 150, 'invoice_date' => '2026-02-01',
            'file' => UploadedFile::fake()->create('neu.pdf', 10, 'application/pdf'),
        ], ['Accept' => 'application/json']);

        $response->assertOk()->assertJsonPath('file_original_name', 'neu.pdf');
        FileStorage::disk()->assertMissing('invoices/alt.pdf');
        $this->assertEquals(150, $invoice->fresh()->amount);
    }

    public function test_an_invoice_can_be_updated_without_a_receipt(): void
    {
        $user = $this->apiUser();
        $managementGroup = $this->makeManagementGroup($user);
        $invoice = $this->makeInvoice($user, $managementGroup);

        $this->putJson(route('api.invoices.update', $invoice), [
            'invoice_issuer_id' => $invoice->invoice_issuer_id, 'amount' => 80, 'invoice_date' => '2026-02-01',
            'disable_amount_cap' => true,
        ])->assertOk()->assertJsonPath('disable_amount_cap', true);
    }

    public function test_an_invoice_cannot_be_moved_to_a_foreign_issuer(): void
    {
        $user = $this->apiUser();
        $invoice = $this->makeInvoice($user, $this->makeManagementGroup($user));

        $this->putJson(route('api.invoices.update', $invoice), [
            'invoice_issuer_id' => $this->makeIssuer(User::factory()->create())->id,
            'amount' => 80, 'invoice_date' => '2026-02-01',
        ])->assertForbidden();
    }

    public function test_deleting_an_invoice_removes_its_receipt(): void
    {
        Storage::fake(FileStorage::diskName());
        $user = $this->apiUser();
        FileStorage::disk()->put('invoices/beleg.pdf', 'x');
        $invoice = $this->makeInvoice($user, $this->makeManagementGroup($user), ['file_path' => 'invoices/beleg.pdf']);

        $this->deleteJson(route('api.invoices.destroy', $invoice))->assertNoContent();

        $this->assertNull(Invoice::find($invoice->id));
        FileStorage::disk()->assertMissing('invoices/beleg.pdf');
    }

    public function test_an_invoice_without_receipt_can_be_deleted(): void
    {
        $user = $this->apiUser();
        $invoice = $this->makeInvoice($user, $this->makeManagementGroup($user));

        $this->deleteJson(route('api.invoices.destroy', $invoice))->assertNoContent();
        $this->assertNull(Invoice::find($invoice->id));
    }

    public function test_a_refund_is_created_as_income_with_a_negative_cost_row(): void
    {
        $user = $this->apiUser();
        $managementGroup = $this->makeManagementGroup($user);

        $response = $this->postJson(route('api.invoices.store'), $this->createPayload($user, $managementGroup, [
            'amount' => -66.51, 'description' => 'Stromrückzahlung',
        ]));

        $response->assertCreated();
        $this->assertEquals(-66.51, Invoice::find($response->json('id'))->costs()->sum('amount'));
    }

    public function test_an_effective_amount_with_the_opposite_sign_is_rejected(): void
    {
        $user = $this->apiUser();
        $managementGroup = $this->makeManagementGroup($user);

        $this->postJson(route('api.invoices.store'), $this->createPayload($user, $managementGroup, [
            'amount' => -50, 'effective_amount' => 50,
        ]))->assertUnprocessable()->assertJsonValidationErrors('effective_amount');
    }

    public function test_manual_occurrences_with_mixed_signs_are_rejected(): void
    {
        $user = $this->apiUser();
        $managementGroup = $this->makeManagementGroup($user);

        $this->postJson(route('api.invoices.store'), $this->createPayload($user, $managementGroup, [
            'periodicity' => 'manual', 'amount' => null,
            'occurrences' => [
                ['occurrence_date' => '2026-01-15', 'amount' => -40],
                ['occurrence_date' => '2026-02-15', 'amount' => 60],
            ],
        ]))->assertUnprocessable()->assertJsonValidationErrors('occurrences.1.amount');
    }

    public function test_an_update_may_turn_an_invoice_into_a_refund(): void
    {
        $user = $this->apiUser();
        $invoice = $this->makeInvoice($user, $this->makeManagementGroup($user));

        $this->putJson(route('api.invoices.update', $invoice), [
            'invoice_issuer_id' => $invoice->invoice_issuer_id, 'amount' => -30, 'effective_amount' => -25,
            'invoice_date' => '2026-02-01',
        ])->assertOk()->assertJsonPath('effective_amount', -25);
    }

    public function test_the_index_can_be_filtered_by_income_and_expense(): void
    {
        $user = $this->apiUser();
        $managementGroup = $this->makeManagementGroup($user);
        $expense = $this->makeInvoice($user, $managementGroup);
        $income = $this->makeInvoice($user, $managementGroup, ['amount' => -20]);

        $this->getJson(route('api.invoices.index', ['type' => 'income']))->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $income->id);
        $this->getJson(route('api.invoices.index', ['type' => 'expense']))->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $expense->id);
    }

    public function test_an_unknown_type_filter_is_rejected(): void
    {
        $this->apiUser();

        $this->getJson(route('api.invoices.index', ['type' => 'sonstiges']))
            ->assertUnprocessable()->assertJsonValidationErrors('type');
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function searchTerms(): array
    {
        return [
            'Rechnungssteller-Name' => ['werke'],
            'Rechnungssteller-Vorname' => ['Erika'],
            'Rechnungssteller-Firma' => ['Energie GmbH'],
            'Beschreibung' => ['Hauslicht'],
        ];
    }

    #[DataProvider('searchTerms')]
    public function test_the_search_matches_issuer_and_description(string $term): void
    {
        $user = $this->apiUser();
        $managementGroup = $this->makeManagementGroup($user);
        $issuer = $this->makeIssuer($user);
        $issuer->update(['first_name' => 'Erika', 'company_name' => 'Energie GmbH']);
        $match = $this->makeInvoice($user, $managementGroup, ['invoice_issuer_id' => $issuer->id, 'description' => 'Hauslicht']);
        $other = InvoiceIssuer::create([
            'user_group_id' => $this->groupOf($user)->id, 'name' => 'Landkreis', 'street' => 'A', 'postal_code' => '1', 'city' => 'B',
        ]);
        $this->makeInvoice($user, $managementGroup, ['invoice_issuer_id' => $other->id, 'description' => 'Abfall']);

        $this->getJson(route('api.invoices.index', ['search' => $term]))->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $match->id);
    }

    public function test_the_search_combines_invoice_number_and_date_range(): void
    {
        $user = $this->apiUser();
        $managementGroup = $this->makeManagementGroup($user);
        $match = $this->makeInvoice($user, $managementGroup, ['invoice_number' => 'RE-2026-17', 'invoice_date' => '2026-03-10']);
        $this->makeInvoice($user, $managementGroup, ['invoice_number' => 'RE-2026-17', 'invoice_date' => '2026-05-10']);
        $this->makeInvoice($user, $managementGroup, ['invoice_number' => 'RE-2025-01', 'invoice_date' => '2026-03-11']);

        $this->getJson(route('api.invoices.index', [
            'invoice_number' => '2026-17', 'date_from' => '2026-03-01', 'date_to' => '2026-03-31',
        ]))->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $match->id);
    }

    public function test_a_receipt_can_be_attached_replaced_downloaded_and_deleted(): void
    {
        Storage::fake(FileStorage::diskName());
        $user = $this->apiUser();
        $invoice = $this->makeInvoice($user, $this->makeManagementGroup($user), ['amount' => -20]);

        $this->post(route('api.invoices.file.store', $invoice), [
            'file' => UploadedFile::fake()->create('erstattung.pdf', 10, 'application/pdf'),
        ], ['Accept' => 'application/json'])->assertOk()->assertJsonPath('file_original_name', 'erstattung.pdf');
        $firstPath = $invoice->fresh()->file_path;

        $this->post(route('api.invoices.file.store', $invoice), [
            'file' => UploadedFile::fake()->create('neu.pdf', 10, 'application/pdf'),
        ], ['Accept' => 'application/json'])->assertOk();
        FileStorage::disk()->assertMissing($firstPath);

        $download = $this->get(route('api.invoices.file.show', $invoice));
        $download->assertOk();
        $this->assertStringContainsString('neu.pdf', $download->headers->get('content-disposition'));

        $secondPath = $invoice->fresh()->file_path;
        $this->deleteJson(route('api.invoices.file.destroy', $invoice))->assertNoContent();
        FileStorage::disk()->assertMissing($secondPath);
        $this->assertNull($invoice->fresh()->file_path);
    }

    public function test_attaching_requires_a_valid_file(): void
    {
        $user = $this->apiUser();
        $invoice = $this->makeInvoice($user, $this->makeManagementGroup($user));

        $this->postJson(route('api.invoices.file.store', $invoice), [])
            ->assertUnprocessable()->assertJsonValidationErrors('file');
    }

    public function test_downloading_a_missing_receipt_is_not_found(): void
    {
        $user = $this->apiUser();
        $invoice = $this->makeInvoice($user, $this->makeManagementGroup($user));

        $this->getJson(route('api.invoices.file.show', $invoice))->assertNotFound();
    }

    public function test_receipts_of_foreign_invoices_are_forbidden(): void
    {
        $owner = User::factory()->create();
        $invoice = $this->makeInvoice($owner, $this->makeManagementGroup($owner), ['file_path' => 'invoices/x.pdf']);
        $this->apiUser();

        $this->getJson(route('api.invoices.file.show', $invoice))->assertForbidden();
        $this->deleteJson(route('api.invoices.file.destroy', $invoice))->assertForbidden();
        $this->postJson(route('api.invoices.file.store', $invoice), [])->assertForbidden();
    }

    // KI-generiert
    public function test_an_invoice_can_be_created_without_generating_cost_rows(): void
    {
        $user = $this->apiUser();
        $managementGroup = $this->makeManagementGroup($user);

        $id = $this->postJson(route('api.invoices.store'), $this->createPayload($user, $managementGroup, [
            'generate_costs' => false, 'billing_interval_id' => null, 'cost_category_id' => null,
        ]))->assertCreated()->json('id');

        $this->assertSame(0, Invoice::find($id)->costs()->count());
    }

    // KI-generiert
    public function test_a_recurring_invoice_needs_its_recurrence_only_when_cost_rows_are_generated(): void
    {
        $user = $this->apiUser();
        $managementGroup = $this->makeManagementGroup($user);
        $recurring = ['periodicity' => 'monthly', 'recurrence_start' => null, 'recurrence_end' => null];

        $this->postJson(route('api.invoices.store'), $this->createPayload($user, $managementGroup, $recurring))
            ->assertUnprocessable()->assertJsonValidationErrors(['recurrence_start', 'recurrence_end']);
        $this->postJson(route('api.invoices.store'), $this->createPayload($user, $managementGroup, [...$recurring, 'generate_costs' => false]))
            ->assertCreated();
    }

    // KI-generiert
    public function test_generating_cost_rows_still_requires_interval_and_category(): void
    {
        $user = $this->apiUser();
        $managementGroup = $this->makeManagementGroup($user);

        $this->postJson(route('api.invoices.store'), $this->createPayload($user, $managementGroup, [
            'billing_interval_id' => null, 'cost_category_id' => null,
        ]))->assertUnprocessable()->assertJsonValidationErrors(['billing_interval_id', 'cost_category_id']);
    }
}
