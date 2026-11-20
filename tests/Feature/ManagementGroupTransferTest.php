<?php

// KI-generiert

namespace Tests\Feature;

use App\Exceptions\GroupMismatchException;
use App\Http\Controllers\ManagementGroupController;
use App\Models\CostCategory;
use App\Models\Group;
use App\Models\Invoice;
use App\Models\InvoiceIssuer;
use App\Models\Lease;
use App\Models\ManagementGroup;
use App\Models\Tenant;
use App\Models\User;
use App\Services\GroupOwnership\GroupOwnership;
use App\Services\GroupOwnership\ManagementGroupTransfer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Api\Concerns\CreatesApiFixtures;
use Tests\TestCase;

/**
 * Eine Verwaltungsgruppe zieht mit ihren Stammdaten in eine andere Benutzergruppe um.
 */
class ManagementGroupTransferTest extends TestCase
{
    use CreatesApiFixtures, RefreshDatabase;

    private User $user;

    private Group $shared;

    private ManagementGroup $moving;

    private ManagementGroup $staying;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
        $this->shared = Group::create(['name' => 'Gemeinsam', 'owner_id' => $this->user->id]);
        $this->shared->users()->attach($this->user->id, ['role' => 'admin']);
        $this->moving = $this->makeManagementGroup($this->user);
        $this->moving->update(['description' => 'Umzug']);
        $this->staying = $this->makeManagementGroup($this->user);
        $this->staying->update(['description' => 'Bleibt']);
    }

    private function own(): int
    {
        return $this->groupOf($this->user)->id;
    }

    private function leaseIn(ManagementGroup $managementGroup, Tenant ...$tenants): Lease
    {
        $rentalUnit = $this->makeRentalUnit($this->user, managementUnit: $this->makeManagementUnit($this->user, $managementGroup));
        $lease = Lease::create(['rental_unit_id' => $rentalUnit->id, 'start_date' => '2026-01-01', 'rent_adjustment_type' => 'fixed', 'cold_rent' => 500]);
        $lease->tenants()->attach(collect($tenants)->pluck('id'));

        return $lease;
    }

    private function tenant(string $lastName, ?int $groupId = null): Tenant
    {
        return Tenant::create(['user_group_id' => $groupId ?? $this->own(), 'first_name' => 'Max', 'last_name' => $lastName, 'street' => 'A', 'postal_code' => '1', 'city' => 'B']);
    }

    private function issuer(string $name, ?int $groupId = null): InvoiceIssuer
    {
        return InvoiceIssuer::create(['user_group_id' => $groupId ?? $this->own(), 'name' => $name, 'street' => 'A', 'postal_code' => '1', 'city' => 'B']);
    }

    private function category(string $name): CostCategory
    {
        return CostCategory::create(['user_group_id' => $this->own(), 'name' => $name, 'is_relevant_for_billing' => true]);
    }

    private function invoiceWithCost(ManagementGroup $managementGroup, InvoiceIssuer $issuer, CostCategory $category, ?int $accountId = null): Invoice
    {
        $invoice = Invoice::create([
            'invoice_issuer_id' => $issuer->id, 'invoice_issuer_account_id' => $accountId, 'amount' => 10,
            'invoice_date' => '2026-01-01', 'periodicity' => 'once',
            'costable_type' => $managementGroup->getMorphClass(), 'costable_id' => $managementGroup->id,
        ]);
        $invoice->costs()->create([
            'costable_type' => $managementGroup->getMorphClass(), 'costable_id' => $managementGroup->id,
            'billing_interval_id' => $managementGroup->billingIntervals()->first()->id,
            'cost_category_id' => $category->id, 'amount' => 10,
        ]);

        return $invoice;
    }

    public function test_entries_used_only_here_move_along(): void
    {
        $tenant = $this->tenant('Allein');
        $this->leaseIn($this->moving, $tenant);
        $issuer = $this->issuer('Nur hier');
        $category = $this->category('Nur hier');
        $this->moving->costCategories()->attach($category->id, ['allocation_key' => 'area']);
        $this->invoiceWithCost($this->moving, $issuer, $category);

        $report = app(ManagementGroupTransfer::class)->move($this->moving, $this->shared->id);

        $this->assertSame($this->shared->id, $this->moving->fresh()->user_group_id);
        $this->assertSame($this->shared->id, $this->moving->user_group_id);
        foreach ([$tenant, $issuer, $category] as $model) {
            $this->assertSame($this->shared->id, $model->fresh()->user_group_id);
        }
        $this->assertSame(['Mieter „Max Allein“', 'Rechnungssteller „Nur hier“', 'Kategorie „Nur hier“'], $report->moved);
        $this->assertSame([[], []], [$report->copied, $report->reused]);
        $this->assertSame(1, Tenant::count());
    }

    public function test_entries_used_elsewhere_are_copied_and_repointed(): void
    {
        $tenant = $this->tenant('Beide');
        $movingLease = $this->leaseIn($this->moving, $tenant);
        $stayingLease = $this->leaseIn($this->staying, $tenant);
        $issuer = $this->issuer('Stadtwerke');
        $account = $issuer->accounts()->create(['customer_number' => 'W-1']);
        $category = $this->category('Lohn');
        $this->moving->costCategories()->attach($category->id, ['allocation_key' => 'area']);
        $this->staying->costCategories()->attach($category->id, ['allocation_key' => 'area']);
        $movingInvoice = $this->invoiceWithCost($this->moving, $issuer, $category, $account->id);
        $stayingInvoice = $this->invoiceWithCost($this->staying, $issuer, $category, $account->id);

        $report = app(ManagementGroupTransfer::class)->move($this->moving, $this->shared->id);

        $tenantCopy = $movingLease->tenants()->sole();
        $this->assertNotSame($tenant->id, $tenantCopy->id);
        $this->assertSame($this->shared->id, $tenantCopy->user_group_id);
        $this->assertTrue($stayingLease->tenants()->sole()->is($tenant));
        $this->assertSame($this->own(), $tenant->fresh()->user_group_id);

        $movingInvoice->refresh();
        $this->assertSame($this->shared->id, $movingInvoice->invoiceIssuer->user_group_id);
        $this->assertSame('W-1', $movingInvoice->invoiceIssuerAccount->customer_number);
        $this->assertSame($movingInvoice->invoice_issuer_id, $movingInvoice->invoiceIssuerAccount->invoice_issuer_id);
        $this->assertSame([$issuer->id, $account->id], [$stayingInvoice->fresh()->invoice_issuer_id, $stayingInvoice->fresh()->invoice_issuer_account_id]);

        $categoryCopy = $movingInvoice->costs()->sole()->costCategory;
        $this->assertSame([$this->shared->id, 'Lohn'], [$categoryCopy->user_group_id, $categoryCopy->name]);
        $this->assertSame([$categoryCopy->id], $this->moving->costCategories()->pluck('cost_categories.id')->all());
        $this->assertSame($category->id, $stayingInvoice->costs()->sole()->cost_category_id);
        $this->assertSame(['Mieter „Max Beide“', 'Rechnungssteller „Stadtwerke“', 'Kategorie „Lohn“'], $report->copied);
    }

    public function test_an_equal_entry_of_the_new_group_is_reused(): void
    {
        $tenant = $this->tenant('Gleich');
        $existingTenant = $this->tenant('Gleich', $this->shared->id);
        $lease = $this->leaseIn($this->moving, $tenant);
        $issuer = $this->issuer('Stadtwerke');
        $issuer->accounts()->create(['customer_number' => 'W-1']);
        $existingIssuer = $this->issuer('Stadtwerke', $this->shared->id);
        $category = $this->category('Lohn');
        $existingCategory = CostCategory::create(['user_group_id' => $this->shared->id, 'name' => 'Lohn', 'is_relevant_for_billing' => true]);
        $this->moving->costCategories()->attach($category->id, ['allocation_key' => 'area']);
        // Altdaten: die Kategorie der neuen Gruppe hängt schon an der Verwaltungsgruppe.
        GroupOwnership::suspended(fn () => $this->moving->costCategories()->attach($existingCategory->id, ['allocation_key' => 'area']));
        $invoice = $this->invoiceWithCost($this->moving, $issuer, $category, $issuer->accounts()->sole()->id);

        $report = app(ManagementGroupTransfer::class)->move($this->moving, $this->shared->id);

        $this->assertTrue($lease->tenants()->sole()->is($existingTenant));
        $this->assertTrue($invoice->fresh()->invoiceIssuer->is($existingIssuer));
        $this->assertSame('W-1', $invoice->fresh()->invoiceIssuerAccount->customer_number);
        $this->assertSame($existingCategory->id, $invoice->costs()->sole()->cost_category_id);
        $this->assertSame([$existingCategory->id], $this->moving->costCategories()->pluck('cost_categories.id')->all());
        $this->assertCount(3, $report->reused);
        $this->assertSame(['Gleichen Eintrag der neuen Gruppe verwendet: Mieter „Max Gleich“, Rechnungssteller „Stadtwerke“, Kategorie „Lohn“'], $report->lines());
    }

    public function test_moving_into_the_same_group_changes_nothing(): void
    {
        $report = app(ManagementGroupTransfer::class)->move($this->moving, $this->own());

        $this->assertTrue($report->isEmpty());
        $this->assertSame([], $report->lines());
        $this->assertSame(['moved' => [], 'copied' => [], 'reused' => []], $report->toArray());
    }

    public function test_a_bare_group_change_with_foreign_master_data_is_rejected(): void
    {
        $this->leaseIn($this->moving, $this->tenant('Alt'));

        $this->expectException(GroupMismatchException::class);
        $this->expectExceptionMessage('Mieter „Max Alt“');

        $this->moving->update(['user_group_id' => $this->shared->id]);
    }

    public function test_the_web_form_moves_the_master_data_and_reports_it(): void
    {
        $this->leaseIn($this->moving, $this->tenant('Web'));

        $this->actingAs($this->user)
            ->put(route('management-groups.update', $this->moving), ['user_group_id' => $this->shared->id, 'description' => 'Umzug neu'])
            ->assertRedirect(route('management-groups.show', $this->moving))
            ->assertSessionHas(ManagementGroupController::TRANSFER_REPORT, ['Mit verschoben: Mieter „Max Web“']);

        $this->assertSame([$this->shared->id, 'Umzug neu'], [$this->moving->fresh()->user_group_id, $this->moving->fresh()->description]);
        $this->actingAs($this->user)->followingRedirects()
            ->put(route('management-groups.update', $this->moving), ['user_group_id' => $this->own(), 'description' => 'Zurück'])
            ->assertOk()->assertSee('data-transfer-report', false)->assertSee('Mit verschoben: Mieter „Max Web“');
    }

    public function test_the_edit_form_explains_the_move(): void
    {
        $this->actingAs($this->user)->get(route('management-groups.edit', $this->moving))
            ->assertOk()->assertSee('data-transfer-hint', false);
    }

    public function test_the_api_reports_the_transfer(): void
    {
        Sanctum::actingAs($this->user);
        $this->leaseIn($this->moving, $this->tenant('Api'));

        $this->putJson(route('api.management-groups.update', $this->moving), ['user_group_id' => $this->shared->id, 'description' => 'Umzug'])
            ->assertOk()
            ->assertJsonPath('user_group_id', $this->shared->id)
            ->assertJsonPath('transfer.moved', ['Mieter „Max Api“'])
            ->assertJsonPath('transfer.copied', []);
    }
}
